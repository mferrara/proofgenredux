<?php

namespace Tests\Feature;

use App\Models\Photo;
use App\Models\PhotoIssue;
use App\Models\Show;
use App\Models\ShowClass;
use App\Services\PhotoImportIdentityResolver;
use App\Services\PhotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * End-to-end exercise of the resolver decision matrix using real JPG bytes
 * from storage/sample_images. Each test runs the full PhotoService pipeline
 * (resolver → archive → original → DB → bury or quarantine) and asserts on
 * filesystem layout, the photos table, the photo_issues table, and graveyard
 * contents.
 */
class PhotoImportResolverMatrixTest extends TestCase
{
    use RefreshDatabase;

    private string $tempPath;

    private string $rawSampleBytes;

    private string $numberedSampleBytes;

    protected function setUp(): void
    {
        parent::setUp();

        // Real JPGs from storage/sample_images are large (~20MB each) and the resolver/archive
        // pipeline holds bytes in memory across hash + archive write + verify. Bump the test
        // process's memory ceiling so this file doesn't OOM in isolation.
        ini_set('memory_limit', '512M');

        $rawFixture = base_path('storage/sample_images/23R41/121/IMG_02631.jpg');
        $numberedFixture = base_path('storage/sample_images/22Buck/007/22BUCK_00093.jpg');
        if (! is_file($rawFixture) || ! is_file($numberedFixture)) {
            $this->markTestSkipped('Sample image fixtures missing.');
        }
        $this->rawSampleBytes = file_get_contents($rawFixture);
        $this->numberedSampleBytes = file_get_contents($numberedFixture);

        config(['testing.skip_file_operations' => true]);
        config(['proofgen.rename_files' => true]);
        config(['proofgen.archive_enabled' => true]);

        $this->tempPath = storage_path('app/resolver_matrix_test_'.uniqid());
        File::makeDirectory($this->tempPath.'/fullsize', 0755, true);
        File::makeDirectory($this->tempPath.'/archive', 0755, true);

        config(['proofgen.fullsize_home_dir' => $this->tempPath.'/fullsize']);
        config(['proofgen.archive_home_dir' => $this->tempPath.'/archive']);
        config(['filesystems.disks.fullsize' => [
            'driver' => 'local', 'root' => $this->tempPath.'/fullsize', 'throw' => true,
        ]]);
        config(['filesystems.disks.archive' => [
            'driver' => 'local', 'root' => $this->tempPath.'/archive', 'throw' => true,
        ]]);
        Storage::forgetDisk('fullsize');
        Storage::forgetDisk('archive');

        Show::withoutEvents(function () {
            Show::create(['id' => '22Buck', 'name' => '22Buck']);
            Show::create(['id' => '23R41', 'name' => '23R41']);
        });
        ShowClass::withoutEvents(function () {
            ShowClass::create(['id' => '22Buck_007', 'show_id' => '22Buck', 'name' => '007']);
            ShowClass::create(['id' => '22Buck_008', 'show_id' => '22Buck', 'name' => '008']);
            ShowClass::create(['id' => '23R41_121', 'show_id' => '23R41', 'name' => '121']);
        });
    }

    protected function tearDown(): void
    {
        if (isset($this->tempPath) && File::exists($this->tempPath)) {
            File::deleteDirectory($this->tempPath);
        }
        parent::tearDown();
    }

    public function test_raw_camera_image_imports_with_caller_supplied_proof_number_and_buries_source(): void
    {
        Storage::disk('fullsize')->put('23R41/121/IMG_02631.jpg', $this->rawSampleBytes);

        $result = app(PhotoService::class)->processPhoto('23R41/121/IMG_02631.jpg', '23R41_00001', false, false);

        $photo = $result['photo']->fresh();
        $this->assertSame(sha1($this->rawSampleBytes), $photo->sha1);
        $this->assertSame('IMG_02631.jpg', $photo->original_filename);
        $this->assertSame('23R41_00001', $photo->proof_number);
        $this->assertSame('23R41/121/23R41_00001.jpg', $photo->archive_path);
        $this->assertNotNull($photo->archived_at);

        $this->assertTrue(Storage::disk('fullsize')->exists('23R41/121/originals/23R41_00001.jpg'));
        $this->assertTrue(Storage::disk('archive')->exists('23R41/121/23R41_00001.jpg'));
        $this->assertSame($this->rawSampleBytes, Storage::disk('archive')->get('23R41/121/23R41_00001.jpg'));
        $this->assertFalse(Storage::disk('fullsize')->exists('23R41/121/IMG_02631.jpg'));

        // Source is in graveyard with a sidecar.
        $graveyardFiles = Storage::disk('fullsize')->allFiles('_graveyard');
        $imageFiles = array_values(array_filter($graveyardFiles, fn ($p) => str_ends_with($p, '.jpg')));
        $sidecars = array_values(array_filter($graveyardFiles, fn ($p) => str_ends_with($p, '.jpg.json')));
        $this->assertCount(1, $imageFiles);
        $this->assertCount(1, $sidecars);
        $sidecar = json_decode(Storage::disk('fullsize')->get($sidecars[0]), true);
        $this->assertSame('post_import_source', $sidecar['reason']);
        $this->assertSame('IMG_02631.jpg', $sidecar['context']['original_filename']);
        $this->assertSame($photo->id, $sidecar['context']['photo_id']);
    }

    public function test_already_numbered_filename_imports_under_embedded_proof_number_without_caller_override(): void
    {
        // Simulate "rehydrated original" — file shows up with show pattern, no Photo row exists.
        Storage::disk('fullsize')->put('22Buck/007/22BUCK_00093.jpg', $this->numberedSampleBytes);

        $result = app(PhotoService::class)->processPhoto('22Buck/007/22BUCK_00093.jpg', null, false, false);

        $photo = $result['photo']->fresh();
        $this->assertSame('22BUCK_00093', $photo->proof_number);
        $this->assertSame(PhotoImportIdentityResolver::IMPORT_NEW, $result['plan']->decision);
        $this->assertFalse($result['plan']->allocatesNewProofNumber);
        $this->assertTrue(Storage::disk('fullsize')->exists('22Buck/007/originals/22BUCK_00093.jpg'));
    }

    public function test_re_dropping_the_same_image_is_idempotent_and_does_not_create_another_photo(): void
    {
        // First import.
        Storage::disk('fullsize')->put('22Buck/007/22BUCK_00093.jpg', $this->numberedSampleBytes);
        app(PhotoService::class)->processPhoto('22Buck/007/22BUCK_00093.jpg', null, false, false);

        $this->assertSame(1, Photo::count());

        // Same bytes, same numbered filename, dropped again — operator retry.
        Storage::disk('fullsize')->put('22Buck/007/22BUCK_00093.jpg', $this->numberedSampleBytes);
        $result = app(PhotoService::class)->processPhoto('22Buck/007/22BUCK_00093.jpg', null, false, false);

        $this->assertSame(PhotoImportIdentityResolver::IDEMPOTENT_EXISTING, $result['plan']->decision);
        $this->assertSame(1, Photo::count());
        $this->assertNull($result['issue']);
        $this->assertFalse(Storage::disk('fullsize')->exists('22Buck/007/22BUCK_00093.jpg'));
        // Two graveyard burials now (both retry sources live in graveyard).
        $graveyardImages = array_values(array_filter(
            Storage::disk('fullsize')->allFiles('_graveyard'),
            fn ($p) => str_ends_with($p, '.jpg'),
        ));
        $this->assertCount(2, $graveyardImages);
    }

    public function test_same_sha_in_a_different_class_creates_duplicate_content_issue(): void
    {
        // Pre-import the same bytes elsewhere.
        Photo::create([
            'id' => '22Buck_008_22BUCK_00050',
            'show_class_id' => '22Buck_008',
            'proof_number' => '22BUCK_00050',
            'file_type' => 'jpg',
            'sha1' => sha1($this->rawSampleBytes),
            'original_filename' => 'IMG_02631.jpg',
        ]);

        // Now drop the same bytes into a different class as a raw camera filename.
        Storage::disk('fullsize')->put('23R41/121/IMG_02631.jpg', $this->rawSampleBytes);
        $result = app(PhotoService::class)->processPhoto('23R41/121/IMG_02631.jpg', null, false, false);

        $this->assertNull($result['photo']);
        $issue = $result['issue']->fresh();
        $this->assertSame(PhotoIssue::TYPE_DUPLICATE_CONTENT, $issue->issue_type);
        $this->assertSame('22Buck_008_22BUCK_00050', $issue->existing_photo_id);
        $this->assertFalse(Storage::disk('fullsize')->exists('23R41/121/IMG_02631.jpg'));
        $this->assertTrue(Storage::disk('fullsize')->exists($issue->quarantine_path));
        $this->assertStringStartsWith('23R41/121/_import_conflicts/IMG_02631_', $issue->quarantine_path);
    }

    public function test_proof_collision_quarantines_incoming_and_leaves_existing_untouched(): void
    {
        // Existing photo at 22Buck_007 with that exact proof number, with the *numbered* sample's bytes.
        Photo::create([
            'id' => '22Buck_007_22BUCK_00093',
            'show_class_id' => '22Buck_007',
            'proof_number' => '22BUCK_00093',
            'file_type' => 'jpg',
            'sha1' => sha1($this->numberedSampleBytes),
        ]);

        // Now drop a DIFFERENT image with that same numbered filename.
        Storage::disk('fullsize')->put('22Buck/007/22BUCK_00093.jpg', $this->rawSampleBytes);
        $result = app(PhotoService::class)->processPhoto('22Buck/007/22BUCK_00093.jpg', null, false, false);

        $issue = $result['issue']->fresh();
        $this->assertSame(PhotoIssue::TYPE_PROOF_COLLISION, $issue->issue_type);
        $this->assertSame(sha1($this->rawSampleBytes), $issue->incoming_sha1);
        $this->assertSame(sha1($this->numberedSampleBytes), $issue->existing_sha1);

        // Existing photo's sha is untouched.
        $this->assertSame(sha1($this->numberedSampleBytes), Photo::find('22Buck_007_22BUCK_00093')->sha1);
        // Incoming source is in _import_conflicts/, not deleted, not in originals.
        $this->assertFalse(Storage::disk('fullsize')->exists('22Buck/007/22BUCK_00093.jpg'));
        $this->assertFalse(Storage::disk('fullsize')->exists('22Buck/007/originals/22BUCK_00093.jpg'));
        $this->assertTrue(Storage::disk('fullsize')->exists($issue->quarantine_path));
    }

    public function test_source_is_preserved_when_archive_root_is_missing(): void
    {
        // Real-image equivalent of the existing synthetic-bytes test in ArchiveBackupTest.
        $missingArchiveRoot = $this->tempPath.'/missing-archive-drive';
        config(['proofgen.archive_home_dir' => $missingArchiveRoot]);
        config(['filesystems.disks.archive.root' => $missingArchiveRoot]);
        Storage::forgetDisk('archive');

        Storage::disk('fullsize')->put('23R41/121/IMG_02631.jpg', $this->rawSampleBytes);

        try {
            app(PhotoService::class)->processPhoto('23R41/121/IMG_02631.jpg', '23R41_00001', false, false);
            $this->fail('Expected archive root validation to fail.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Archive root does not exist', $e->getMessage());
        }

        $this->assertTrue(Storage::disk('fullsize')->exists('23R41/121/IMG_02631.jpg'));
        $this->assertFalse(Storage::disk('fullsize')->exists('23R41/121/originals/23R41_00001.jpg'));
        $this->assertDatabaseMissing('photos', ['id' => '23R41_121_23R41_00001']);
        // Graveyard untouched — bury never ran.
        $this->assertSame([], Storage::disk('fullsize')->allFiles('_graveyard'));
    }

    public function test_bypass_resolver_imports_under_explicit_proof_number_even_when_classification_would_block(): void
    {
        // Mirror the real "Replace existing with incoming" UI flow: the operator decides to
        // displace the existing photo, the UI buries its original/archive and DELETES the row
        // before calling processPhoto with bypassResolver. Without bypassResolver, the resolver
        // would re-flag this as a PROOF_COLLISION the moment we re-staged the file.
        Storage::disk('fullsize')->put('22Buck/007/22BUCK_00050.jpg', $this->rawSampleBytes);

        $result = app(PhotoService::class)->processPhoto(
            imagePath: '22Buck/007/22BUCK_00050.jpg',
            proofNumberOverride: '22BUCK_00050',
            debug: false,
            dispatchJobs: false,
            bypassResolver: true,
        );

        // No issue created; resolver was skipped entirely.
        $this->assertNull($result['issue']);
        $this->assertNull($result['plan']);
        $this->assertNotNull($result['photo']);
        $this->assertSame('22BUCK_00050', $result['photo']->proof_number);
        $this->assertSame(sha1($this->rawSampleBytes), $result['photo']->fresh()->sha1);
        $this->assertTrue(Storage::disk('fullsize')->exists('22Buck/007/originals/22BUCK_00050.jpg'));
    }

    public function test_bypass_resolver_requires_explicit_proof_number(): void
    {
        Storage::disk('fullsize')->put('22Buck/007/22BUCK_00060.jpg', $this->rawSampleBytes);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('bypassResolver requires an explicit proofNumberOverride');

        app(PhotoService::class)->processPhoto(
            imagePath: '22Buck/007/22BUCK_00060.jpg',
            proofNumberOverride: null,
            debug: false,
            dispatchJobs: false,
            bypassResolver: true,
        );
    }

    public function test_invalid_numbered_filename_treated_as_raw_and_imported_with_override(): void
    {
        // `22BUCK_00093.jpg` for show `22Buck` is recognized as numbered; but `22BUCK_999.jpg`
        // (3 digits) doesn't match the strict 5-digit pattern — treated as raw camera filename.
        Storage::disk('fullsize')->put('22Buck/007/22BUCK_999.jpg', $this->rawSampleBytes);

        $result = app(PhotoService::class)->processPhoto('22Buck/007/22BUCK_999.jpg', '22BUCK_00200', false, false);

        $this->assertSame(PhotoImportIdentityResolver::IMPORT_NEW, $result['plan']->decision);
        $this->assertTrue($result['plan']->allocatesNewProofNumber);
        $this->assertNull($result['plan']->intendedProofNumber);
        $this->assertSame('22BUCK_00200', $result['photo']->proof_number);
        $this->assertSame('22BUCK_999.jpg', $result['photo']->original_filename);
    }
}
