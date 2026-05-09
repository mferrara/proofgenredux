<?php

namespace Tests\Feature;

use App\Models\Photo;
use App\Models\PhotoIssue;
use App\Models\Show;
use App\Models\ShowClass;
use App\Services\PhotoAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhotoAuditServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        config(['testing.skip_file_operations' => true]);
        config(['proofgen.archive_enabled' => true]);

        $this->tempPath = storage_path('app/audit_service_test_'.uniqid());
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
            Show::create(['id' => 'SHOW1', 'name' => 'SHOW1']);
        });
        ShowClass::withoutEvents(function () {
            ShowClass::create(['id' => 'SHOW1_101', 'show_id' => 'SHOW1', 'name' => '101']);
        });
    }

    protected function tearDown(): void
    {
        if (isset($this->tempPath) && File::exists($this->tempPath)) {
            File::deleteDirectory($this->tempPath);
        }
        parent::tearDown();
    }

    public function test_backfills_missing_photo_sha1_when_repair_enabled(): void
    {
        Storage::disk('fullsize')->put('SHOW1/101/originals/SHOW1_00001.jpg', 'audit bytes');
        Photo::create([
            'id' => 'SHOW1_101_SHOW1_00001', 'show_class_id' => 'SHOW1_101',
            'proof_number' => 'SHOW1_00001', 'file_type' => 'jpg', 'sha1' => null,
        ]);

        $result = app(PhotoAuditService::class)->auditAll(repair: true);

        $this->assertSame(sha1('audit bytes'), Photo::find('SHOW1_101_SHOW1_00001')->sha1);
        $this->assertSame(1, $result['stats']['photos_repaired']);
    }

    public function test_reports_sha1_mismatch_with_local_original_without_auto_fixing(): void
    {
        // Set up the photo with a *correct* archive copy so archive isn't a confound.
        Storage::disk('fullsize')->put('SHOW1/101/originals/SHOW1_00002.jpg', 'real bytes');
        Storage::disk('archive')->put('SHOW1/101/SHOW1_00002.jpg', 'real bytes');
        Photo::create([
            'id' => 'SHOW1_101_SHOW1_00002', 'show_class_id' => 'SHOW1_101',
            'proof_number' => 'SHOW1_00002', 'file_type' => 'jpg', 'sha1' => sha1('different bytes'),
            'archive_path' => 'SHOW1/101/SHOW1_00002.jpg',
            'archive_sha1' => sha1('real bytes'),
            'archive_size' => strlen('real bytes'),
            'archived_at' => now(),
        ]);

        $result = app(PhotoAuditService::class)->auditAll(repair: true);

        $finding = collect($result['findings'])->firstWhere('photo_id', 'SHOW1_101_SHOW1_00002');
        $this->assertSame('photos_sha1_mismatch_with_original', $finding['status']);
        // Mismatch is never auto-resolved.
        $this->assertFalse($finding['repaired']);
        $this->assertDatabaseHas('photo_issues', [
            'existing_photo_id' => 'SHOW1_101_SHOW1_00002',
            'issue_type' => PhotoIssue::TYPE_METADATA_MISMATCH,
            'status' => PhotoIssue::STATUS_OPEN,
        ]);
    }

    public function test_finds_duplicate_sha1_groups(): void
    {
        Photo::create([
            'id' => 'SHOW1_101_SHOW1_00010', 'show_class_id' => 'SHOW1_101',
            'proof_number' => 'SHOW1_00010', 'file_type' => 'jpg', 'sha1' => sha1('same'),
        ]);
        Photo::create([
            'id' => 'SHOW1_101_SHOW1_00011', 'show_class_id' => 'SHOW1_101',
            'proof_number' => 'SHOW1_00011', 'file_type' => 'jpg', 'sha1' => sha1('same'),
        ]);

        $result = app(PhotoAuditService::class)->auditAll();

        $this->assertSame(1, $result['stats']['duplicate_sha1_groups']);
        $duplicateGroup = collect($result['findings'])->firstWhere('kind', 'duplicate_sha1_group');
        $this->assertSame(2, $duplicateGroup['count']);
        $this->assertSame(sha1('same'), $duplicateGroup['sha1']);
    }

    public function test_finds_orphan_originals_in_filesystem(): void
    {
        // File on disk in originals, no Photo row.
        Storage::disk('fullsize')->put('SHOW1/101/originals/SHOW1_00099.jpg', 'orphaned');

        $result = app(PhotoAuditService::class)->auditAll();

        $this->assertSame(1, $result['stats']['orphan_originals']);
        $orphan = collect($result['findings'])->firstWhere('kind', 'orphan_original');
        $this->assertSame('SHOW1', $orphan['show']);
        $this->assertSame('101', $orphan['class']);
        $this->assertSame('SHOW1_00099', $orphan['proof_number']);
    }

    public function test_finds_photos_missing_both_original_and_archive(): void
    {
        Photo::create([
            'id' => 'SHOW1_101_SHOW1_00050', 'show_class_id' => 'SHOW1_101',
            'proof_number' => 'SHOW1_00050', 'file_type' => 'jpg', 'sha1' => sha1('lost'),
            // no archive_path, no original on disk
        ]);

        $result = app(PhotoAuditService::class)->auditAll();

        $this->assertSame(1, $result['stats']['photos_without_original_and_archive']);
        $this->assertDatabaseHas('photo_issues', [
            'existing_photo_id' => 'SHOW1_101_SHOW1_00050',
            'issue_type' => PhotoIssue::TYPE_MISSING_ORIGINAL,
            'status' => PhotoIssue::STATUS_OPEN,
        ]);
    }

    public function test_does_not_recreate_open_issue_for_same_photo_on_subsequent_runs(): void
    {
        Storage::disk('fullsize')->put('SHOW1/101/originals/SHOW1_00060.jpg', 'real bytes');
        Photo::create([
            'id' => 'SHOW1_101_SHOW1_00060', 'show_class_id' => 'SHOW1_101',
            'proof_number' => 'SHOW1_00060', 'file_type' => 'jpg', 'sha1' => sha1('different'),
        ]);

        $audit = app(PhotoAuditService::class);
        $audit->auditAll();
        $audit->auditAll();

        $this->assertSame(1, PhotoIssue::where('existing_photo_id', 'SHOW1_101_SHOW1_00060')->count());
    }
}
