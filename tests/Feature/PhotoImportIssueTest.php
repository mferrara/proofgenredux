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

class PhotoImportIssueTest extends TestCase
{
    use RefreshDatabase;

    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        config(['testing.skip_file_operations' => true]);
        config(['proofgen.rename_files' => true]);
        config(['proofgen.archive_enabled' => true]);

        $this->tempPath = storage_path('app/import_issue_test_'.uniqid());
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
            Show::create(['id' => '22Buck', 'name' => '22Buck']);
        });
        ShowClass::withoutEvents(function () {
            ShowClass::create(['id' => '22Buck_007', 'show_id' => '22Buck', 'name' => '007']);
            ShowClass::create(['id' => '22Buck_008', 'show_id' => '22Buck', 'name' => '008']);
        });
    }

    protected function tearDown(): void
    {
        if (isset($this->tempPath) && File::exists($this->tempPath)) {
            File::deleteDirectory($this->tempPath);
        }

        parent::tearDown();
    }

    public function test_duplicate_content_creates_issue_and_quarantines_source(): void
    {
        // Existing photo somewhere else with the same bytes.
        Photo::create([
            'id' => '22Buck_008_22BUCK_00010',
            'show_class_id' => '22Buck_008',
            'proof_number' => '22BUCK_00010',
            'file_type' => 'jpg',
            'sha1' => sha1('shared bytes'),
        ]);

        Storage::disk('fullsize')->put('22Buck/007/IMG_0001.jpg', 'shared bytes');

        $result = app(PhotoService::class)->processPhoto('22Buck/007/IMG_0001.jpg', null, false, false);

        $this->assertNull($result['photo']);
        $this->assertNotNull($result['issue']);
        $this->assertSame(PhotoImportIdentityResolver::DUPLICATE_CONTENT, $result['plan']->decision);

        $issue = $result['issue']->fresh();
        $this->assertSame(PhotoIssue::TYPE_DUPLICATE_CONTENT, $issue->issue_type);
        $this->assertSame(PhotoIssue::STATUS_OPEN, $issue->status);
        $this->assertSame('22Buck', $issue->show_id);
        $this->assertSame('22Buck_007', $issue->show_class_id);
        $this->assertSame('22Buck/007/IMG_0001.jpg', $issue->source_path);
        $this->assertSame(sha1('shared bytes'), $issue->incoming_sha1);
        $this->assertSame(strlen('shared bytes'), $issue->incoming_size);
        $this->assertSame('22Buck_008_22BUCK_00010', $issue->existing_photo_id);
        $this->assertSame('22BUCK_00010', $issue->existing_proof_number);

        // Source removed from ingest path; lives in _import_conflicts/ now.
        $this->assertFalse(Storage::disk('fullsize')->exists('22Buck/007/IMG_0001.jpg'));
        $this->assertTrue(Storage::disk('fullsize')->exists($issue->quarantine_path));
        $this->assertStringStartsWith('22Buck/007/_import_conflicts/IMG_0001_', $issue->quarantine_path);

        // Sidecar present
        $sidecarPath = $issue->quarantine_path.'.json';
        $this->assertTrue(Storage::disk('fullsize')->exists($sidecarPath));
        $sidecar = json_decode(Storage::disk('fullsize')->get($sidecarPath), true);
        $this->assertSame('import_conflict', $sidecar['reason']);
        $this->assertSame('22Buck/007/IMG_0001.jpg', $sidecar['original_path']);
        $this->assertSame(PhotoImportIdentityResolver::DUPLICATE_CONTENT, $sidecar['context']['decision']);

        // No new photo created.
        $this->assertCount(1, Photo::all());
    }

    public function test_proof_collision_creates_issue_and_quarantines_source(): void
    {
        // Existing photo with proof number 22BUCK_00093 in the same class with DIFFERENT bytes.
        Photo::create([
            'id' => '22Buck_007_22BUCK_00093',
            'show_class_id' => '22Buck_007',
            'proof_number' => '22BUCK_00093',
            'file_type' => 'jpg',
            'sha1' => sha1('original bytes'),
        ]);

        Storage::disk('fullsize')->put('22Buck/007/22BUCK_00093.jpg', 'incoming different bytes');

        $result = app(PhotoService::class)->processPhoto('22Buck/007/22BUCK_00093.jpg', null, false, false);

        $this->assertNull($result['photo']);
        $issue = $result['issue']->fresh();
        $this->assertSame(PhotoIssue::TYPE_PROOF_COLLISION, $issue->issue_type);
        $this->assertSame('22BUCK_00093', $issue->intended_proof_number);
        $this->assertSame(sha1('incoming different bytes'), $issue->incoming_sha1);
        $this->assertSame(sha1('original bytes'), $issue->existing_sha1);
        $this->assertFalse(Storage::disk('fullsize')->exists('22Buck/007/22BUCK_00093.jpg'));
        $this->assertTrue(Storage::disk('fullsize')->exists($issue->quarantine_path));

        // Existing photo untouched.
        $this->assertSame(sha1('original bytes'), Photo::find('22Buck_007_22BUCK_00093')->sha1);
    }

    public function test_idempotent_retry_buries_source_and_does_not_create_issue(): void
    {
        // Existing photo with the SAME bytes and SAME proof number in SAME class.
        Storage::disk('fullsize')->put('22Buck/007/originals/22BUCK_00050.jpg', 'identical bytes');
        Photo::create([
            'id' => '22Buck_007_22BUCK_00050',
            'show_class_id' => '22Buck_007',
            'proof_number' => '22BUCK_00050',
            'file_type' => 'jpg',
            'sha1' => sha1('identical bytes'),
        ]);

        // Same image dropped into ingest folder again as numbered file.
        Storage::disk('fullsize')->put('22Buck/007/22BUCK_00050.jpg', 'identical bytes');

        $result = app(PhotoService::class)->processPhoto('22Buck/007/22BUCK_00050.jpg', null, false, false);

        $this->assertNotNull($result['photo']);
        $this->assertNull($result['issue']);
        $this->assertSame(PhotoImportIdentityResolver::IDEMPOTENT_EXISTING, $result['plan']->decision);

        // Source is buried, not in import_conflicts.
        $this->assertFalse(Storage::disk('fullsize')->exists('22Buck/007/22BUCK_00050.jpg'));
        $this->assertSame([], Storage::disk('fullsize')->allFiles('22Buck/007/_import_conflicts'));
        $graveyardFiles = Storage::disk('fullsize')->allFiles('_graveyard');
        $this->assertNotEmpty($graveyardFiles);
    }
}
