<?php

namespace Tests\Feature;

use App\Jobs\Photo\ImportPhoto;
use App\Jobs\ShowClass\ImportClassPhotos;
use App\Models\Photo;
use App\Models\Show;
use App\Models\ShowClass;
use App\Services\PhotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A class folder made in Finder after the show page was opened has no class
 * record. Importing from it used to move and number the photos and then crash
 * before any proof was queued (proofgen-feedback #13). Every import entry point
 * must create the record first, or leave the files alone.
 */
class ImportWithoutClassRecordTest extends TestCase
{
    use RefreshDatabase;

    private const SHOW = 'NORECORD';

    protected function setUp(): void
    {
        parent::setUp();

        config(['proofgen.archive_enabled' => false]);
        Storage::fake('fullsize');
        config(['proofgen.fullsize_home_dir' => Storage::disk('fullsize')->path('')]);

        Show::withoutEvents(fn () => Show::create(['id' => self::SHOW, 'name' => self::SHOW]));
    }

    public function test_show_import_creates_the_class_record_before_queuing_photos(): void
    {
        Bus::fake();
        Storage::disk('fullsize')->put(self::SHOW.'/062/_Z5A0001.JPG', 'jpeg');
        Storage::disk('fullsize')->put(self::SHOW.'/bad name/_Z5A0002.JPG', 'jpeg');

        $queued = Show::find(self::SHOW)->importPendingImages();

        $this->assertSame(1, $queued);
        $this->assertTrue(ShowClass::where('id', self::SHOW.'_062')->exists());
        $this->assertFalse(ShowClass::where('name', 'bad name')->exists());
        Bus::assertDispatchedTimes(ImportPhoto::class, 1);
        Bus::assertDispatched(ImportPhoto::class, fn (ImportPhoto $job) => str_contains($job->image_path, '/062/'));
    }

    public function test_class_import_creates_the_class_record_instead_of_doing_nothing(): void
    {
        Bus::fake([ImportPhoto::class]);
        Storage::disk('fullsize')->put(self::SHOW.'/063/_Z5A0003.JPG', 'jpeg');

        (new ImportClassPhotos(self::SHOW, '063'))->handle();

        $this->assertTrue(ShowClass::where('id', self::SHOW.'_063')->exists());
        Bus::assertDispatchedTimes(ImportPhoto::class, 1);
    }

    public function test_a_photo_is_never_moved_when_its_class_cannot_have_a_record(): void
    {
        $path = self::SHOW.'/bad name/_Z5A0004.JPG';
        Storage::disk('fullsize')->put($path, 'jpeg');

        try {
            app(PhotoService::class)->processPhoto($path, null, false, false);
            $this->fail('Expected the import to be refused.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('no class record', $e->getMessage());
        }

        $this->assertTrue(Storage::disk('fullsize')->exists($path));
        $this->assertSame(0, Photo::count());
    }
}
