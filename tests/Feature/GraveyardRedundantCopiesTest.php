<?php

use App\Livewire\AppStatusBar;
use App\Models\Photo;
use App\Models\Show;
use App\Models\ShowClass;
use App\Services\GraveyardService;
use App\Services\SafeFileMover;
use App\Services\WorkingDiskSpace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('fullsize');
    Storage::fake('archive');
    Cache::flush();
    config([
        'proofgen.fullsize_home_dir' => Storage::disk('fullsize')->path(''),
        'proofgen.archive_enabled' => false,
        'proofgen.graveyard.redundant_alert_gb' => 0,
    ]);

    Show::withoutEvents(fn () => Show::create(['id' => 'GY', 'name' => 'GY']));
    ShowClass::withoutEvents(fn () => ShowClass::create(['id' => 'GY_001', 'show_id' => 'GY', 'name' => '001']));

    // An imported photo: the original in originals/, the camera file buried after import.
    $this->imported = function (string $proof, string $bytes, ?string $originalBytes = null, array $photo = []) {
        Storage::disk('fullsize')->put("GY/001/originals/{$proof}.jpg", $originalBytes ?? $bytes);
        Storage::disk('fullsize')->put("GY/001/_Z5A{$proof}.JPG", $bytes);

        $model = new Photo;
        $model->forceFill(['id' => "GY_001_{$proof}", 'show_class_id' => 'GY_001', 'proof_number' => $proof, 'file_type' => 'jpg', 'sha1' => sha1($bytes)] + $photo);
        Photo::withoutEvents(fn () => $model->save());

        return app(SafeFileMover::class)->bury('fullsize', "GY/001/_Z5A{$proof}.JPG", SafeFileMover::REASON_POST_IMPORT_SOURCE, [
            'sha1' => sha1($bytes), 'size' => strlen($bytes), 'photo_id' => $model->id,
        ]);
    };

    $this->roomy = function (?string $level = null) {
        $space = Mockery::mock(WorkingDiskSpace::class)->makePartial();
        $space->shouldReceive('level')->andReturn($level);
        $space->shouldReceive('freeBytes')->andReturn($level ? 4 * 1024 ** 3 : 200 * 1024 ** 3);
        app()->instance(WorkingDiskSpace::class, $space);
    };
    ($this->roomy)();
});

it('counts a buried camera file as a second copy only while its imported original matches', function () {
    ($this->imported)('00001', 'first photo');
    ($this->imported)('00002', 'second photo', originalBytes: 'second phot0'); // same size, but not the same photo
    ($this->imported)('00003', 'third photo', originalBytes: 'short');

    // A deleted photo's original is not a second copy of anything.
    Storage::disk('fullsize')->put('GY/001/loose.jpg', 'loose');
    app(SafeFileMover::class)->bury('fullsize', 'GY/001/loose.jpg', SafeFileMover::REASON_PHOTO_DELETED);

    expect(app(GraveyardService::class)->redundantImportCopies())->toBe(['count' => 2, 'bytes' => strlen('first photo') + strlen('second photo')]);

    $result = app(GraveyardService::class)->removeRedundantImportCopies();

    // 00002 passed the cheap size check but fails the hash: it stays.
    expect($result)->toBe(['deleted_count' => 1, 'freed_bytes' => strlen('first photo'), 'kept_count' => 1])
        ->and(Storage::disk('fullsize')->exists('GY/001/originals/00001.jpg'))->toBeTrue()
        ->and(count(Storage::disk('fullsize')->allFiles('_graveyard')))->toBe(6); // three files + sidecars left
});

it('waits for the backup copy when backups are on', function () {
    config(['proofgen.archive_enabled' => true]);
    ($this->imported)('00001', 'not archived yet');
    ($this->imported)('00002', 'archived', photo: ['archive_sha1' => sha1('archived')]);

    expect(app(GraveyardService::class)->redundantImportCopies()['count'])->toBe(1);
});

it('shows the strip only while there is something to remove', function () {
    Livewire::test(AppStatusBar::class)->assertDontSee('second copies');

    ($this->imported)('00001', 'first photo');
    Cache::flush();

    Livewire::test(AppStatusBar::class)
        ->assertSee('second copies of 1 photos')
        ->call('removeRedundantCopies')
        ->assertDontSee('second copies');

    expect(Storage::disk('fullsize')->allFiles('_graveyard'))->toBe([])
        ->and(Storage::disk('fullsize')->get('GY/001/originals/00001.jpg'))->toBe('first photo');
});

it('stays quiet below the configured size unless the disk is low', function () {
    config(['proofgen.graveyard.redundant_alert_gb' => 1]);
    ($this->imported)('00001', 'first photo');

    Livewire::test(AppStatusBar::class)->assertDontSee('second copies')->assertDontSee('free</strong>', false);

    ($this->roomy)('critical');
    Livewire::test(AppStatusBar::class)->assertSee('second copies')->assertSee('4 GB free')->assertSee('Free some space before importing');
});
