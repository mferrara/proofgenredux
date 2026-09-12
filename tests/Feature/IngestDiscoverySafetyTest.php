<?php

namespace Tests\Feature;

use App\Jobs\Photo\ImportPhoto;
use App\Models\Show;
use App\Models\ShowClass;
use App\Proofgen\Utility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Ingest discovery must only surface genuine JPG/JPEG files sitting directly in a
 * show/class ingest folder. Quarantine, sidecars, nested originals, hidden files and
 * non-JPEG files (even ones whose path contains "jpg") must never be dispatched.
 */
class IngestDiscoverySafetyTest extends TestCase
{
    use RefreshDatabase;

    private Show $show;

    protected function setUp(): void
    {
        parent::setUp();

        config(['testing.skip_file_operations' => true]);
        Storage::fake('fullsize');

        Show::withoutEvents(function () {
            $this->show = Show::create(['id' => 'SHOW1', 'name' => 'SHOW1']);
        });

        ShowClass::withoutEvents(function () {
            ShowClass::create(['id' => 'SHOW1_101', 'show_id' => 'SHOW1', 'name' => '101']);
        });
    }

    private function seedFixtures(): void
    {
        // Genuine ingest files in the registered class folder.
        Storage::disk('fullsize')->put('SHOW1/101/IMG_0001.jpg', 'a');
        Storage::disk('fullsize')->put('SHOW1/101/IMG_0002.JPG', 'b');
        Storage::disk('fullsize')->put('SHOW1/101/IMG_0003.jpeg', 'c');
        Storage::disk('fullsize')->put('SHOW1/101/IMG_0004.JPEG', 'd');

        // A second class folder on disk with no ShowClass row. Show-level discovery
        // must still find its ingest file.
        Storage::disk('fullsize')->put('SHOW1/127/IMG_0005.jpg', 'e');

        // Quarantined JPEG plus its .jpg.json sidecar: excluded (nested conflict dir).
        Storage::disk('fullsize')->put('SHOW1/101/_import_conflicts/IMG_9999.jpg', 'quarantined');
        Storage::disk('fullsize')->put('SHOW1/101/_import_conflicts/IMG_9999_20260101-120000_deadbeef.jpg.json', '{}');

        // A .jpg.json sidecar sitting directly in the ingest folder.
        Storage::disk('fullsize')->put('SHOW1/101/IMG_0001_20260101-120000_deadbeef.jpg.json', '{}');

        // Non-JPEG under a path containing "jpg", and a plain non-JPEG.
        Storage::disk('fullsize')->put('SHOW1/101/jpg-notes.txt', 'notes');
        Storage::disk('fullsize')->put('SHOW1/101/IMG_0006.png', 'png');

        // Nested original and hidden housekeeping files: excluded.
        Storage::disk('fullsize')->put('SHOW1/101/originals/SHOW1_00001.jpg', 'original');
        Storage::disk('fullsize')->put('SHOW1/101/.DS_Store', 'hidden');
        Storage::disk('fullsize')->put('SHOW1/101/.hidden.jpg', 'hidden-image');
    }

    public function test_class_discovery_returns_only_immediate_jpeg_ingest_files(): void
    {
        $this->seedFixtures();

        $showClass = ShowClass::find('SHOW1_101');
        $paths = array_map(fn ($image) => $image->path(), $showClass->getImagesPendingImport());
        sort($paths);

        $this->assertSame([
            'SHOW1/101/IMG_0001.jpg',
            'SHOW1/101/IMG_0002.JPG',
            'SHOW1/101/IMG_0003.jpeg',
            'SHOW1/101/IMG_0004.JPEG',
        ], $paths);
    }

    public function test_show_discovery_aggregates_unregistered_class_folders_without_duplicates(): void
    {
        $this->seedFixtures();

        $paths = array_map(fn ($image) => $image->path(), $this->show->getImagesPendingImport());
        sort($paths);

        $this->assertSame([
            'SHOW1/101/IMG_0001.jpg',
            'SHOW1/101/IMG_0002.JPG',
            'SHOW1/101/IMG_0003.jpeg',
            'SHOW1/101/IMG_0004.JPEG',
            'SHOW1/127/IMG_0005.jpg',
        ], $paths);

        $this->assertSame(count($paths), count(array_unique($paths)), 'Discovery must not return duplicates.');
    }

    public function test_show_import_dispatches_only_genuine_ingest_files(): void
    {
        $this->seedFixtures();
        Bus::fake();

        $processed = $this->show->importPendingImages();

        $expected = [
            'SHOW1/101/IMG_0001.jpg',
            'SHOW1/101/IMG_0002.JPG',
            'SHOW1/101/IMG_0003.jpeg',
            'SHOW1/101/IMG_0004.JPEG',
            'SHOW1/127/IMG_0005.jpg',
        ];
        sort($expected);

        $this->assertSame(count($expected), $processed);

        $dispatched = [];
        Bus::assertDispatched(ImportPhoto::class, function (ImportPhoto $job) use (&$dispatched) {
            $dispatched[] = $job->image_path;

            return true;
        });
        sort($dispatched);

        $this->assertSame($expected, $dispatched);
        Bus::assertDispatchedTimes(ImportPhoto::class, count($expected));
    }

    public function test_show_discovery_keeps_valid_underscore_classes_but_excludes_reserved_folders(): void
    {
        Storage::disk('fullsize')->put('SHOW1/_warmup/real.jpg', 'image');
        foreach (['originals', '_import_conflicts', '_graveyard', '_conflicts', '.hidden'] as $directory) {
            Storage::disk('fullsize')->put('SHOW1/'.$directory.'/hidden.jpg', 'housekeeping');
        }

        $paths = array_map(fn ($image) => $image->path(), $this->show->getImagesPendingImport());

        $this->assertSame(['SHOW1/_warmup/real.jpg'], $paths);
    }

    public function test_utility_extension_matching_is_final_extension_only(): void
    {
        Storage::disk('fullsize')->put('SHOW1/101/notes-about-jpg.txt', 'x');
        Storage::disk('fullsize')->put('SHOW1/101/photo.png', 'x');
        Storage::disk('fullsize')->put('SHOW1/101/photo.jpg.json', 'x');
        Storage::disk('fullsize')->put('SHOW1/101/real.jpg', 'x');
        Storage::disk('fullsize')->put('SHOW1/101/real2.JPEG', 'x');

        $contents = Utility::getContentsOfPath('SHOW1/101', false);
        $paths = array_map(fn ($image) => $image->path(), $contents['images']);
        sort($paths);

        $this->assertSame(['SHOW1/101/real.jpg', 'SHOW1/101/real2.JPEG'], $paths);
    }
}
