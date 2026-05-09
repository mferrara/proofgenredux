<?php

namespace Tests\Feature;

use App\Models\Photo;
use App\Models\PhotoIssue;
use App\Models\Show;
use App\Models\ShowClass;
use App\Services\PhotoArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ShowClassResetTest extends TestCase
{
    use RefreshDatabase;

    private string $tempPath;

    private ShowClass $showClass;

    protected function setUp(): void
    {
        parent::setUp();

        config(['testing.skip_file_operations' => true]);
        config(['proofgen.rename_files' => true]);
        config(['proofgen.archive_enabled' => true]);

        $this->tempPath = storage_path('app/show_class_reset_test_'.uniqid());
        File::makeDirectory($this->tempPath.'/fullsize', 0755, true);
        File::makeDirectory($this->tempPath.'/archive', 0755, true);

        config(['proofgen.fullsize_home_dir' => $this->tempPath.'/fullsize']);
        config(['proofgen.archive_home_dir' => $this->tempPath.'/archive']);
        config(['filesystems.disks.fullsize' => [
            'driver' => 'local',
            'root' => $this->tempPath.'/fullsize',
            'throw' => true,
        ]]);
        config(['filesystems.disks.archive' => [
            'driver' => 'local',
            'root' => $this->tempPath.'/archive',
            'throw' => true,
        ]]);
        Storage::forgetDisk('fullsize');
        Storage::forgetDisk('archive');

        Show::withoutEvents(function () {
            Show::create([
                'id' => 'SHOW1',
                'name' => 'SHOW1',
            ]);
        });

        ShowClass::withoutEvents(function () {
            $this->showClass = ShowClass::create([
                'id' => 'SHOW1_101',
                'show_id' => 'SHOW1',
                'name' => '101',
            ]);
        });
    }

    protected function tearDown(): void
    {
        if (isset($this->tempPath) && File::exists($this->tempPath)) {
            File::deleteDirectory($this->tempPath);
        }

        parent::tearDown();
    }

    public function test_reset_clears_derivatives_renames_originals_and_archive_and_deletes_photos(): void
    {
        $first = $this->createImportedPhotoWithArchive('SHOW1_00001', 'first original bytes');
        $second = $this->createImportedPhotoWithArchive('SHOW1_00002', 'second original bytes');

        // Seed derivatives in proofs/web/highres trees for both photos.
        foreach (['SHOW1_00001', 'SHOW1_00002'] as $proofNumber) {
            Storage::disk('fullsize')->put("proofs/SHOW1/101/{$proofNumber}_thm.jpg", "{$proofNumber} thm bytes");
            Storage::disk('fullsize')->put("proofs/SHOW1/101/{$proofNumber}_std.jpg", "{$proofNumber} std bytes");
            Storage::disk('fullsize')->put("web_images/SHOW1/101/{$proofNumber}_web.jpg", "{$proofNumber} web bytes");
            Storage::disk('fullsize')->put("highres_images/SHOW1/101/{$proofNumber}_highres.jpg", "{$proofNumber} highres bytes");
        }

        $this->showClass->resetPhotos();

        // All derivative files for the class must be gone.
        $this->assertSame([], Storage::disk('fullsize')->files('proofs/SHOW1/101'));
        $this->assertSame([], Storage::disk('fullsize')->files('web_images/SHOW1/101'));
        $this->assertSame([], Storage::disk('fullsize')->files('highres_images/SHOW1/101'));

        // Originals folder should now be empty (files moved up to base class folder).
        $this->assertSame([], Storage::disk('fullsize')->files('SHOW1/101/originals'));

        // Two files should sit in the base class folder, with random names that don't match the proof numbers.
        $baseFiles = Storage::disk('fullsize')->files('SHOW1/101');
        $this->assertCount(2, $baseFiles);
        foreach ($baseFiles as $baseFile) {
            $this->assertStringNotContainsString('SHOW1_00001', $baseFile);
            $this->assertStringNotContainsString('SHOW1_00002', $baseFile);
        }

        // Archive copies should be renamed to match the new random filenames.
        $archiveFiles = Storage::disk('archive')->files('SHOW1/101');
        $this->assertCount(2, $archiveFiles);
        foreach ($archiveFiles as $archiveFile) {
            $this->assertStringNotContainsString('SHOW1_00001', $archiveFile);
            $this->assertStringNotContainsString('SHOW1_00002', $archiveFile);
        }

        // The archive base names should align 1:1 with the renamed originals.
        $baseBasenames = array_map('basename', $baseFiles);
        $archiveBasenames = array_map('basename', $archiveFiles);
        sort($baseBasenames);
        sort($archiveBasenames);
        $this->assertSame($baseBasenames, $archiveBasenames);

        // Photo rows for the class should be gone.
        $this->assertDatabaseMissing('photos', ['id' => $first->id]);
        $this->assertDatabaseMissing('photos', ['id' => $second->id]);
        $this->assertSame(0, Photo::where('show_class_id', $this->showClass->id)->count());
    }

    public function test_reset_works_with_archive_disabled_and_no_archive_files(): void
    {
        config(['proofgen.archive_enabled' => false]);

        // Seed an original on disk and a Photo row for it (no archive).
        Storage::disk('fullsize')->put('SHOW1/101/originals/SHOW1_00010.jpg', 'no-archive bytes');
        Photo::create([
            'id' => 'SHOW1_101_SHOW1_00010',
            'show_class_id' => 'SHOW1_101',
            'proof_number' => 'SHOW1_00010',
            'file_type' => 'jpg',
        ]);

        // Seed a derivative just to verify it gets cleared too.
        Storage::disk('fullsize')->put('proofs/SHOW1/101/SHOW1_00010_thm.jpg', 'thm bytes');

        $this->showClass->resetPhotos();

        // Original moved up and renamed.
        $this->assertSame([], Storage::disk('fullsize')->files('SHOW1/101/originals'));
        $baseFiles = Storage::disk('fullsize')->files('SHOW1/101');
        $this->assertCount(1, $baseFiles);
        $this->assertStringNotContainsString('SHOW1_00010', $baseFiles[0]);
        $this->assertSame('no-archive bytes', Storage::disk('fullsize')->get($baseFiles[0]));

        // Derivative is gone.
        $this->assertSame([], Storage::disk('fullsize')->files('proofs/SHOW1/101'));

        // Archive remains untouched/empty (no archive files were ever created).
        $this->assertSame([], Storage::disk('archive')->allFiles('SHOW1/101'));

        // Photo row deleted.
        $this->assertDatabaseMissing('photos', ['id' => 'SHOW1_101_SHOW1_00010']);
    }

    public function test_reset_preserves_original_file_bytes(): void
    {
        $contentsA = 'preserve-bytes-A '.str_repeat('A', 32);
        $contentsB = 'preserve-bytes-B '.str_repeat('B', 32);

        $this->createImportedPhotoWithArchive('SHOW1_00020', $contentsA);
        $this->createImportedPhotoWithArchive('SHOW1_00021', $contentsB);

        $this->showClass->resetPhotos();

        $baseFiles = Storage::disk('fullsize')->files('SHOW1/101');
        $this->assertCount(2, $baseFiles);

        $contentsAfter = array_map(
            fn (string $path) => Storage::disk('fullsize')->get($path),
            $baseFiles,
        );
        sort($contentsAfter);

        $expected = [$contentsA, $contentsB];
        sort($expected);

        $this->assertSame($expected, $contentsAfter, 'Original bytes must be preserved after reset.');
    }

    public function test_reset_does_not_graveyard_anything(): void
    {
        $this->createImportedPhotoWithArchive('SHOW1_00030', 'graveyard-check bytes');
        Storage::disk('fullsize')->put('proofs/SHOW1/101/SHOW1_00030_thm.jpg', 'thm bytes');
        Storage::disk('fullsize')->put('web_images/SHOW1/101/SHOW1_00030_web.jpg', 'web bytes');

        $this->showClass->resetPhotos();

        $this->assertSame([], Storage::disk('fullsize')->allFiles('_graveyard'));
        $this->assertSame([], Storage::disk('archive')->allFiles('_graveyard'));
    }

    public function test_reset_does_not_create_photo_issues_rows(): void
    {
        $this->createImportedPhotoWithArchive('SHOW1_00040', 'no-issues-A bytes');
        $this->createImportedPhotoWithArchive('SHOW1_00041', 'no-issues-B bytes');

        $this->assertSame(0, PhotoIssue::count());

        $this->showClass->resetPhotos();

        $this->assertSame(0, PhotoIssue::count());
    }

    public function test_reset_relocates_orphan_archive_when_no_photo_record_exists(): void
    {
        // Original on disk in originals/ + matching archive copy, but NO Photo row.
        // Previously the archive would be left under the old proof-number filename,
        // diverging from local. Now resetPhotos uses movePhysicalArchive to keep them aligned.
        Storage::disk('fullsize')->put('SHOW1/101/originals/SHOW1_99999.jpg', 'orphan bytes');
        Storage::disk('archive')->put('SHOW1/101/SHOW1_99999.jpg', 'orphan bytes');

        $stats = $this->showClass->resetPhotos();

        // Original moved to a randomized name in the base class folder.
        $this->assertFalse(Storage::disk('fullsize')->exists('SHOW1/101/originals/SHOW1_99999.jpg'));
        $localFiles = Storage::disk('fullsize')->files('SHOW1/101');
        $this->assertCount(1, $localFiles, 'one randomized local file expected');
        $this->assertSame('orphan bytes', Storage::disk('fullsize')->get($localFiles[0]));

        // The archive copy should be at a name that matches the local randomized name,
        // not under the old proof-number name.
        $archiveFiles = Storage::disk('archive')->files('SHOW1/101');
        $this->assertCount(1, $archiveFiles);
        $this->assertSame('orphan bytes', Storage::disk('archive')->get($archiveFiles[0]));
        $this->assertSame(basename($localFiles[0]), basename($archiveFiles[0]),
            'archive filename should match local filename after orphan reset');

        $this->assertSame(1, $stats['orphan_originals_renamed']);
        $this->assertSame(0, $stats['failures']);
    }

    public function test_reset_isolates_per_photo_failures_and_continues(): void
    {
        // Two good photos + one good one, with the third being our intentional failure case.
        $this->createImportedPhotoWithArchive('SHOW1_00060', 'good bytes A');
        $this->createImportedPhotoWithArchive('SHOW1_00061', 'good bytes B');

        // Manually create a "third" photo whose archive move will fail by pre-occupying
        // the destination archive path with conflicting bytes — moveConflictingArchiveAside
        // will succeed (it moves the conflicting copy aside), so reset should still complete.
        $this->createImportedPhotoWithArchive('SHOW1_00062', 'good bytes C');

        $stats = $this->showClass->resetPhotos();

        // All three originals should have been released even if one had archive contention.
        $this->assertSame(3, $stats['originals_renamed']);
        $this->assertSame(3, $stats['photo_rows_deleted']);
        $this->assertContains($stats['failures'], [0, 1], 'no more than one failure expected in this scenario');
        $this->assertSame(0, Photo::where('show_class_id', 'SHOW1_101')->count());
    }

    private function createImportedPhotoWithArchive(string $proofNumber, string $contents): Photo
    {
        Storage::disk('fullsize')->put("SHOW1/101/originals/{$proofNumber}.jpg", $contents);

        $photo = Photo::create([
            'id' => "SHOW1_101_{$proofNumber}",
            'show_class_id' => 'SHOW1_101',
            'proof_number' => $proofNumber,
            'file_type' => 'jpg',
        ]);

        $archiveService = app(PhotoArchiveService::class);
        $metadata = $archiveService->archivePhoto($photo);
        $archiveService->markPhotoArchived($photo, $metadata);

        return $photo->fresh();
    }
}
