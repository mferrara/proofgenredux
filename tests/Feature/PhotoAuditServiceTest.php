<?php

namespace Tests\Feature;

use App\Models\Photo;
use App\Models\PhotoIssue;
use App\Models\Show;
use App\Models\ShowClass;
use App\Services\PhotoAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $sha = sha1('same');

        // The unique index makes duplicate sha1 rows impossible in normal
        // operation, so drop it just for this test to exercise the audit's
        // defensive duplicate detection (e.g. a pre-migration database).
        DB::statement('DROP INDEX IF EXISTS photos_sha1_unique');

        try {
            DB::table('photos')->insert([
                [
                    'id' => 'SHOW1_101_SHOW1_00010', 'show_class_id' => 'SHOW1_101',
                    'proof_number' => 'SHOW1_00010', 'file_type' => 'jpg', 'sha1' => $sha,
                    'created_at' => now(), 'updated_at' => now(),
                ],
                [
                    'id' => 'SHOW1_101_SHOW1_00011', 'show_class_id' => 'SHOW1_101',
                    'proof_number' => 'SHOW1_00011', 'file_type' => 'jpg', 'sha1' => $sha,
                    'created_at' => now(), 'updated_at' => now(),
                ],
            ]);

            $result = app(PhotoAuditService::class)->auditAll();

            $this->assertSame(1, $result['stats']['duplicate_sha1_groups']);
            $duplicateGroup = collect($result['findings'])->firstWhere('kind', 'duplicate_sha1_group');
            $this->assertSame(2, $duplicateGroup['count']);
            $this->assertSame($sha, $duplicateGroup['sha1']);
        } finally {
            DB::table('photos')->where('sha1', $sha)->delete();
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS photos_sha1_unique ON photos (sha1)');
        }
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

    public function test_finds_ingest_stragglers_for_non_image_files_in_class_folder(): void
    {
        // .DS_Store and image files should be silently ignored / treated as pending.
        Storage::disk('fullsize')->put('SHOW1/101/.DS_Store', 'mac noise');
        Storage::disk('fullsize')->put('SHOW1/101/IMG_0099.jpg', 'pending image bytes');
        // Non-image, non-ignored straggler:
        Storage::disk('fullsize')->put('SHOW1/101/notes.txt', 'operator left a note');
        Storage::disk('fullsize')->put('SHOW1/101/proof_sheet.pdf', 'a pdf');

        $result = app(PhotoAuditService::class)->auditAll();

        $this->assertSame(2, $result['stats']['ingest_stragglers']);
        $stragglers = collect($result['findings'])->where('kind', 'ingest_straggler')->pluck('basename')->all();
        $this->assertContains('notes.txt', $stragglers);
        $this->assertContains('proof_sheet.pdf', $stragglers);
        $this->assertNotContains('.DS_Store', $stragglers);
        $this->assertNotContains('IMG_0099.jpg', $stragglers);

        $this->assertDatabaseHas('photo_issues', [
            'issue_type' => PhotoIssue::TYPE_INGEST_STRAGGLER,
            'source_path' => 'SHOW1/101/notes.txt',
            'status' => PhotoIssue::STATUS_OPEN,
        ]);
    }

    public function test_finds_orphan_quarantine_files_without_open_issue_rows(): void
    {
        // Quarantined file with no matching open photo_issues row.
        Storage::disk('fullsize')->put('SHOW1/101/_import_conflicts/IMG_0001_20260509-001-abc123.jpg', 'orphan');
        Storage::disk('fullsize')->put('SHOW1/101/_import_conflicts/IMG_0001_20260509-001-abc123.jpg.json', '{}');

        // Quarantined file WITH a matching open issue — should not be flagged.
        Storage::disk('fullsize')->put('SHOW1/101/_import_conflicts/IMG_0002_20260509-002-def456.jpg', 'covered');
        PhotoIssue::create([
            'status' => PhotoIssue::STATUS_OPEN,
            'issue_type' => PhotoIssue::TYPE_DUPLICATE_CONTENT,
            'show_id' => 'SHOW1',
            'show_class_id' => 'SHOW1_101',
            'quarantine_path' => 'SHOW1/101/_import_conflicts/IMG_0002_20260509-002-def456.jpg',
            'incoming_sha1' => sha1('covered'),
            'incoming_size' => strlen('covered'),
        ]);

        $result = app(PhotoAuditService::class)->auditAll();

        $this->assertSame(1, $result['stats']['orphan_quarantine_files']);
        $orphan = collect($result['findings'])->firstWhere('kind', 'orphan_quarantine');
        $this->assertSame('IMG_0001_20260509-001-abc123.jpg', $orphan['basename']);
        $this->assertTrue($orphan['sidecar_exists']);

        $this->assertDatabaseHas('photo_issues', [
            'issue_type' => PhotoIssue::TYPE_ORPHAN_QUARANTINE,
            'quarantine_path' => 'SHOW1/101/_import_conflicts/IMG_0001_20260509-001-abc123.jpg',
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
