<?php

use App\Jobs\Cards\DumpCard;
use App\Livewire\CardReaderComponent;
use App\Models\Show;
use App\Services\Cards\CardDumper;
use App\Services\Cards\CardScanner;
use App\Services\Cards\CardVolume;
use App\Services\Cards\CardVolumeFinder;
use App\Services\PhotoArchiveService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Card Reader: two verified copies before anything is removed from a card, and
 * a card is only ever touched when it is provably the one that was scanned.
 */

beforeEach(function () {
    $this->root = storage_path('app/card_reader_test_'.uniqid());
    foreach (['card/DCIM/100EOSR5', 'card/DCIM/CANONMSC', 'working', 'archive'] as $dir) {
        File::makeDirectory($this->root.'/'.$dir, 0755, true);
    }

    config([
        'testing.skip_file_operations' => true,
        'proofgen.fullsize_home_dir' => $this->root.'/working',
        'proofgen.archive_home_dir' => $this->root.'/archive',
        'proofgen.archive_enabled' => true,
        'filesystems.disks.fullsize' => ['driver' => 'local', 'root' => $this->root.'/working', 'throw' => true],
        'filesystems.disks.archive' => ['driver' => 'local', 'root' => $this->root.'/archive', 'throw' => true],
    ]);
    Storage::forgetDisk('fullsize');
    Storage::forgetDisk('archive');

    Show::withoutEvents(fn () => Show::create(['id' => '26AAC', 'name' => '26AAC']));

    foreach (['_Z5A0001.JPG' => 'first', '_Z5A0002.JPG' => 'second', '_Z5A0003.JPG' => 'third'] as $name => $bytes) {
        File::put($this->root.'/card/DCIM/100EOSR5/'.$name, $bytes);
    }
    File::put($this->root.'/card/DCIM/100EOSR5/_Z5A0001.CR3', 'raw');
    File::put($this->root.'/card/DCIM/100EOSR5/._Z5A0001.JPG', 'appledouble');
    File::put($this->root.'/card/DCIM/CANONMSC/M0100.CTG', 'catalog');

    $this->card = fn (string $uuid = 'CARD-A') => new CardVolume(
        mountPoint: $this->root.'/card', name: 'EOS_DIGITAL', volumeUuid: $uuid, deviceIdentifier: 'disk5s1',
        parentWholeDisk: 'disk5', busProtocol: 'Secure Digital', mediaName: 'Built In SDXC Reader', slotKey: 'slot-1',
        filesystem: 'MS-DOS FAT32', totalBytes: 32_000_000_000, freeBytes: 31_000_000_000, writable: true, hasDcim: true, readable: true,
    );

    // The reader currently holds whatever $this->inReader says.
    $this->inReader = ($this->card)();
    $test = $this;
    app()->instance(CardVolumeFinder::class, new class($test) extends CardVolumeFinder
    {
        public function __construct(private $test) {}

        public function all(): array
        {
            return array_filter([$this->test->inReader]);
        }
    });

    $this->dump = function (array $options, array $assignments = ['005' => ['DCIM/100EOSR5/_Z5A0001.JPG', 'DCIM/100EOSR5/_Z5A0002.JPG', 'DCIM/100EOSR5/_Z5A0003.JPG']]) {
        (new DumpCard('test-dump', '26AAC', $this->root.'/card', 'CARD-A', $assignments, $options + ['import' => false, 'clear' => false, 'eject' => false]))
            ->handle(app(CardDumper::class), app(CardVolumeFinder::class));

        return Cache::get(DumpCard::progressKey('test-dump'));
    };
});

afterEach(fn () => File::deleteDirectory($this->root));

it('lists photos in capture order and leaves everything else alone', function () {
    $scan = app(CardScanner::class)->scan(($this->card)());

    expect(array_column($scan['images'], 'name'))->toBe(['_Z5A0001.JPG', '_Z5A0002.JPG', '_Z5A0003.JPG'])
        ->and($scan['skipped'])->toBe(['cr3' => 1]); // AppleDouble and camera catalog files are not even counted
});

it('starts a new class at each pause in shooting', function () {
    $at = fn (int $minute) => ['taken_at' => 1_700_000_000 + $minute * 60];

    expect(app(CardScanner::class)->groupByPauses([$at(0), $at(1), $at(2), $at(12), $at(13), $at(40)], 5))
        ->toBe([[0, 1, 2], [3, 4], [5]]);
});

it('writes a verified copy to the class folder and the archive, then empties the card of exactly those files', function () {
    $progress = ($this->dump)(['clear' => true]);

    expect($progress['state'])->toBe('done')->and($progress['copied'])->toBe(3)->and($progress['cleared'])->toBe(3);

    expect(Storage::disk('fullsize')->get('26AAC/005/_Z5A0002.JPG'))->toBe('second');
    $archived = collect(Storage::disk('archive')->allFiles('26AAC/_cards/005'));
    expect($archived->filter(fn ($f) => str_ends_with($f, '_Z5A0002.JPG'))->count())->toBe(1)
        ->and($archived->filter(fn ($f) => str_ends_with($f, 'manifest.json'))->count())->toBe(1);

    // No staging leftovers for the importer to trip over.
    expect(collect(Storage::disk('fullsize')->allDirectories('26AAC/005'))->filter(fn ($d) => str_contains($d, '.incoming')))->toBeEmpty();

    // The card keeps what was never copied.
    expect(File::exists($this->root.'/card/DCIM/100EOSR5/_Z5A0001.JPG'))->toBeFalse()
        ->and(File::exists($this->root.'/card/DCIM/100EOSR5/_Z5A0001.CR3'))->toBeTrue();
});

it('lets the import rename the card copy on the archive instead of writing the photo a second time', function () {
    ($this->dump)([]);

    $cardCopy = collect(Storage::disk('archive')->allFiles('26AAC/_cards/005'))->first(fn ($f) => str_ends_with($f, '_Z5A0002.JPG'));
    expect($cardCopy)->not->toBeNull();

    // What ImportPhoto does for this photo once it has a proof number.
    $stored = app(PhotoArchiveService::class)->storeContents('26AAC/005/26AAC_00002.jpg', 'second');

    expect($stored['created'])->toBeTrue()
        ->and(Storage::disk('archive')->get('26AAC/005/26AAC_00002.jpg'))->toBe('second')
        // Moved, not copied: the card copy is gone and nothing was written twice.
        ->and(Storage::disk('archive')->exists($cardCopy))->toBeFalse()
        ->and(DB::table('card_files')->where('sha1', sha1('second'))->value('claimed_path'))->toBe('26AAC/005/26AAC_00002.jpg');

    // A photo the Card Reader never saw is still written normally.
    app(PhotoArchiveService::class)->storeContents('26AAC/005/26AAC_00009.jpg', 'from a plain folder import');
    expect(Storage::disk('archive')->get('26AAC/005/26AAC_00009.jpg'))->toBe('from a plain folder import');
});

it('never empties a card when there is no working archive', function () {
    config(['proofgen.archive_enabled' => false]);

    $progress = ($this->dump)(['clear' => true]);

    expect($progress['state'])->toBe('done')->and($progress['copied'])->toBe(3)->and($progress['cleared'])->toBe(0)
        ->and(File::exists($this->root.'/card/DCIM/100EOSR5/_Z5A0001.JPG'))->toBeTrue();
});

it('leaves photos that were not assigned to a class on the card', function () {
    $progress = ($this->dump)(['clear' => true], ['005' => ['DCIM/100EOSR5/_Z5A0001.JPG']]);

    expect($progress['cleared'])->toBe(1)
        ->and(File::exists($this->root.'/card/DCIM/100EOSR5/_Z5A0002.JPG'))->toBeTrue();
});

it('refuses to touch a different card that was put in the reader after scanning', function () {
    $this->inReader = ($this->card)('CARD-B');

    $progress = ($this->dump)(['clear' => true]);

    expect($progress['state'])->toBe('failed')
        ->and($progress['message'])->toBe('Nothing was removed from the card.')
        ->and(File::exists($this->root.'/card/DCIM/100EOSR5/_Z5A0001.JPG'))->toBeTrue()
        ->and(Storage::disk('fullsize')->exists('26AAC/005/_Z5A0001.JPG'))->toBeFalse();
});

it('keeps both photos when a camera reuses a filename', function () {
    Storage::disk('fullsize')->put('26AAC/005/_Z5A0001.JPG', 'an earlier, different photo');

    ($this->dump)([]);

    expect(Storage::disk('fullsize')->get('26AAC/005/_Z5A0001.JPG'))->toBe('an earlier, different photo')
        ->and(collect(Storage::disk('fullsize')->files('26AAC/005'))->filter(fn ($f) => str_contains($f, '_Z5A0001-'))->count())->toBe(1);
});

it('only creates class folders the website will accept', function () {
    Storage::disk('fullsize')->makeDirectory('26AAC');

    Livewire::test(CardReaderComponent::class, ['show_id' => '26AAC'])
        ->set('newClassName', 'Halter_')->call('createClass')->assertHasErrors('newClassName')
        ->set('newClassName', 'has space')->call('createClass')->assertHasErrors('newClassName')
        ->set('newClassName', '012')->call('createClass')->assertHasNoErrors()
        ->assertSet('classFolder', '012');

    expect(Storage::disk('fullsize')->exists('26AAC/012'))->toBeTrue()
        ->and(Storage::disk('fullsize')->exists('26AAC/Halter_'))->toBeFalse();
});

it('picks up the card in the remembered reader and explains a privacy denial', function () {
    Storage::disk('fullsize')->makeDirectory('26AAC/005');
    Cache::forever('cards.slot_key', 'slot-1');

    Livewire::test(CardReaderComponent::class, ['show_id' => '26AAC'])
        ->call('watchReader')
        ->assertSet('mountPoint', $this->root.'/card')
        ->assertSee('3 photos')
        ->assertSee('1 .cr3 left on card');

    $denied = ($this->card)();
    $this->inReader = new CardVolume(...array_merge(array_diff_key($denied->toArray(), ['label' => 1]), ['readable' => false]));

    Livewire::test(CardReaderComponent::class, ['show_id' => '26AAC'])
        ->call('watchReader')
        ->assertSee('macOS is not letting Proofgen read this card')
        ->assertSee('Full Disk Access');
});
