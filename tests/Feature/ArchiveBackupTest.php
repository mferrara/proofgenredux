<?php

namespace Tests\Feature;

use App\Models\Photo;
use App\Models\Show;
use App\Models\ShowClass;
use App\Services\ClassRenameService;
use App\Services\PhotoArchiveService;
use App\Services\PhotoMoveService;
use App\Services\PhotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ArchiveBackupTest extends TestCase
{
    use RefreshDatabase;

    private string $tempPath;

    private ShowClass $sourceClass;

    private ShowClass $targetClass;

    protected function setUp(): void
    {
        parent::setUp();

        config(['testing.skip_file_operations' => true]);
        config(['proofgen.rename_files' => true]);
        config(['proofgen.archive_enabled' => true]);

        $this->tempPath = storage_path('app/archive_backup_test_'.uniqid());
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
            $this->sourceClass = ShowClass::create([
                'id' => 'SHOW1_101',
                'show_id' => 'SHOW1',
                'name' => '101',
            ]);

            $this->targetClass = ShowClass::create([
                'id' => 'SHOW1_102',
                'show_id' => 'SHOW1',
                'name' => '102',
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

    public function test_import_writes_archive_metadata_to_photo(): void
    {
        Storage::disk('fullsize')->put('SHOW1/101/IMG_0001.jpg', 'raw image data');

        $result = app(PhotoService::class)->processPhoto('SHOW1/101/IMG_0001.jpg', 'SHOW1_00001', false, false);
        $photo = $result['photo']->fresh();

        $this->assertTrue(Storage::disk('archive')->exists('SHOW1/101/SHOW1_00001.jpg'));
        $this->assertSame('raw image data', Storage::disk('archive')->get('SHOW1/101/SHOW1_00001.jpg'));
        $this->assertSame(sha1('raw image data'), $photo->sha1);
        $this->assertSame('IMG_0001.jpg', $photo->original_filename);
        $this->assertSame('SHOW1/101/SHOW1_00001.jpg', $photo->archive_path);
        $this->assertSame(sha1('raw image data'), $photo->archive_sha1);
        $this->assertSame(strlen('raw image data'), $photo->archive_size);
        $this->assertNotNull($photo->archived_at);
        $this->assertTrue(Storage::disk('fullsize')->exists('SHOW1/101/originals/SHOW1_00001.jpg'));
        $this->assertFalse(Storage::disk('fullsize')->exists('SHOW1/101/IMG_0001.jpg'));

        // Ingest source is buried, not deleted: it must still be reachable in the graveyard
        // alongside a JSON sidecar describing where it came from.
        $graveyardFiles = Storage::disk('fullsize')->allFiles('_graveyard');
        $imageFiles = array_values(array_filter($graveyardFiles, fn ($p) => str_ends_with($p, '.jpg')));
        $sidecars = array_values(array_filter($graveyardFiles, fn ($p) => str_ends_with($p, '.jpg.json')));
        $this->assertCount(1, $imageFiles);
        $this->assertCount(1, $sidecars);
        $this->assertSame('raw image data', Storage::disk('fullsize')->get($imageFiles[0]));
        $sidecar = json_decode(Storage::disk('fullsize')->get($sidecars[0]), true);
        $this->assertSame('SHOW1/101/IMG_0001.jpg', $sidecar['original_path']);
        $this->assertSame('post_import_source', $sidecar['reason']);
        $this->assertSame('IMG_0001.jpg', $sidecar['context']['original_filename']);
    }

    public function test_archive_collision_moves_existing_copy_aside_without_deleting_it(): void
    {
        Storage::disk('archive')->put('SHOW1/101/SHOW1_00002.jpg', 'older image data');
        Storage::disk('fullsize')->put('SHOW1/101/IMG_0002.jpg', 'new image data');

        app(PhotoService::class)->processPhoto('SHOW1/101/IMG_0002.jpg', 'SHOW1_00002', false, false);

        $this->assertSame('new image data', Storage::disk('archive')->get('SHOW1/101/SHOW1_00002.jpg'));

        $conflicts = Storage::disk('archive')->allFiles('SHOW1/101/_conflicts');
        $this->assertCount(1, $conflicts);
        $this->assertSame('older image data', Storage::disk('archive')->get($conflicts[0]));
    }

    public function test_archive_reuses_identical_existing_copy_without_conflict_file(): void
    {
        Storage::disk('archive')->put('SHOW1/101/SHOW1_00003.jpg', 'same image data');
        Storage::disk('fullsize')->put('SHOW1/101/IMG_0003.jpg', 'same image data');

        app(PhotoService::class)->processPhoto('SHOW1/101/IMG_0003.jpg', 'SHOW1_00003', false, false);

        $this->assertSame('same image data', Storage::disk('archive')->get('SHOW1/101/SHOW1_00003.jpg'));
        $this->assertSame([], Storage::disk('archive')->allFiles('SHOW1/101/_conflicts'));
    }

    public function test_import_fails_before_deleting_source_when_archive_root_is_missing(): void
    {
        $missingArchiveRoot = $this->tempPath.'/missing-archive-drive';

        config(['proofgen.archive_home_dir' => $missingArchiveRoot]);
        config(['filesystems.disks.archive.root' => $missingArchiveRoot]);
        Storage::forgetDisk('archive');

        Storage::disk('fullsize')->put('SHOW1/101/IMG_0008.jpg', 'image without drive');

        try {
            app(PhotoService::class)->processPhoto('SHOW1/101/IMG_0008.jpg', 'SHOW1_00008', false, false);
            $this->fail('Expected archive root validation to fail.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Archive root does not exist', $e->getMessage());
        }

        $this->assertTrue(Storage::disk('fullsize')->exists('SHOW1/101/IMG_0008.jpg'));
        $this->assertFalse(Storage::disk('fullsize')->exists('SHOW1/101/originals/SHOW1_00008.jpg'));
        $this->assertDatabaseMissing('photos', ['id' => 'SHOW1_101_SHOW1_00008']);
        $this->assertFalse(File::exists($missingArchiveRoot));
    }

    public function test_archive_audit_repair_backfills_missing_archive_copy_and_metadata(): void
    {
        Storage::disk('fullsize')->put('SHOW1/101/originals/SHOW1_00004.jpg', 'repair image data');
        Photo::create([
            'id' => 'SHOW1_101_SHOW1_00004',
            'show_class_id' => 'SHOW1_101',
            'proof_number' => 'SHOW1_00004',
            'file_type' => 'jpg',
        ]);

        $this->artisan('proofgen:audit', ['--repair' => true])
            ->assertExitCode(0);

        $photo = Photo::find('SHOW1_101_SHOW1_00004');
        $this->assertTrue(Storage::disk('archive')->exists('SHOW1/101/SHOW1_00004.jpg'));
        $this->assertSame('SHOW1/101/SHOW1_00004.jpg', $photo->archive_path);
        $this->assertSame(sha1('repair image data'), $photo->archive_sha1);
    }

    public function test_photo_move_tracks_archive_copy_and_metadata(): void
    {
        $photo = $this->createImportedPhotoWithArchive('SHOW1_00005', 'move image data');

        $results = app(PhotoMoveService::class)->movePhotos([$photo->id], $this->targetClass->id);

        $this->assertSame(['SHOW1_00005'], $results['success']);
        $this->assertFalse(Storage::disk('archive')->exists('SHOW1/101/SHOW1_00005.jpg'));
        $this->assertTrue(Storage::disk('archive')->exists('SHOW1/102/SHOW1_00005.jpg'));

        $movedPhoto = Photo::find('SHOW1_102_SHOW1_00005');
        $this->assertSame('SHOW1/102/SHOW1_00005.jpg', $movedPhoto->archive_path);
        $this->assertSame(sha1('move image data'), $movedPhoto->archive_sha1);
    }

    public function test_class_rename_tracks_archive_directory_and_photo_metadata(): void
    {
        $photo = $this->createImportedPhotoWithArchive('SHOW1_00006', 'rename image data');

        $result = app(ClassRenameService::class)->renameClass($this->sourceClass, '103');

        $this->assertTrue($result['success']);
        $this->assertFalse(Storage::disk('archive')->exists('SHOW1/101/SHOW1_00006.jpg'));
        $this->assertTrue(Storage::disk('archive')->exists('SHOW1/103/SHOW1_00006.jpg'));
        $this->assertNull(Photo::find($photo->id));

        $renamedPhoto = Photo::find('SHOW1_103_SHOW1_00006');
        $this->assertSame('SHOW1/103/SHOW1_00006.jpg', $renamedPhoto->archive_path);
        $this->assertSame(sha1('rename image data'), $renamedPhoto->archive_sha1);
    }

    public function test_class_rename_moves_derivative_directories_for_each_photo(): void
    {
        $photo = $this->createImportedPhotoWithArchive('SHOW1_00010', 'derivative rename data');

        // Seed derivative trees as they'd exist after generation.
        Storage::disk('fullsize')->put('proofs/SHOW1/101/SHOW1_00010_thm.jpg', 'thm bytes');
        Storage::disk('fullsize')->put('proofs/SHOW1/101/SHOW1_00010_std.jpg', 'std bytes');
        Storage::disk('fullsize')->put('web_images/SHOW1/101/SHOW1_00010_web.jpg', 'web bytes');
        Storage::disk('fullsize')->put('highres_images/SHOW1/101/SHOW1_00010_highres.jpg', 'highres bytes');

        $result = app(ClassRenameService::class)->renameClass($this->sourceClass, '104');

        $this->assertTrue($result['success'], $result['error'] ?? '');

        $this->assertFalse(Storage::disk('fullsize')->exists('proofs/SHOW1/101/SHOW1_00010_thm.jpg'));
        $this->assertFalse(Storage::disk('fullsize')->exists('proofs/SHOW1/101/SHOW1_00010_std.jpg'));
        $this->assertFalse(Storage::disk('fullsize')->exists('web_images/SHOW1/101/SHOW1_00010_web.jpg'));
        $this->assertFalse(Storage::disk('fullsize')->exists('highres_images/SHOW1/101/SHOW1_00010_highres.jpg'));

        $this->assertSame('thm bytes', Storage::disk('fullsize')->get('proofs/SHOW1/104/SHOW1_00010_thm.jpg'));
        $this->assertSame('std bytes', Storage::disk('fullsize')->get('proofs/SHOW1/104/SHOW1_00010_std.jpg'));
        $this->assertSame('web bytes', Storage::disk('fullsize')->get('web_images/SHOW1/104/SHOW1_00010_web.jpg'));
        $this->assertSame('highres bytes', Storage::disk('fullsize')->get('highres_images/SHOW1/104/SHOW1_00010_highres.jpg'));

        $this->assertNull(Photo::find($photo->id));
        $this->assertNotNull(Photo::find('SHOW1_104_SHOW1_00010'));
    }

    public function test_class_reset_tracks_archive_to_reset_filename(): void
    {
        $photo = $this->createImportedPhotoWithArchive('SHOW1_00007', 'reset image data');

        $this->sourceClass->resetPhotos();

        $this->assertDatabaseMissing('photos', ['id' => $photo->id]);
        $this->assertFalse(Storage::disk('archive')->exists('SHOW1/101/SHOW1_00007.jpg'));

        $archiveFiles = Storage::disk('archive')->files('SHOW1/101');
        $this->assertCount(1, $archiveFiles);
        $this->assertSame('reset image data', Storage::disk('archive')->get($archiveFiles[0]));
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
