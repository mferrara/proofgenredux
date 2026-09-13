<?php

namespace Tests\Feature;

use App\Models\Photo;
use App\Models\PhotoIssue;
use App\Models\Show;
use App\Models\ShowClass;
use App\Proofgen\Image;
use App\Services\PhotoImportIdentityResolver;
use App\Services\PhotoService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Global exact-source-byte (sha1) uniqueness: the DB unique index is the last
 * line of defence, the resolver/Image guard rejects duplicates before writes,
 * and same-class retries reuse the existing record instead of allocating a new
 * proof number.
 */
class PhotoSha1UniquenessTest extends TestCase
{
    use RefreshDatabase;

    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        config(['testing.skip_file_operations' => true]);
        config(['proofgen.rename_files' => true]);
        config(['proofgen.archive_enabled' => true]);

        $this->tempPath = storage_path('app/sha1_uniqueness_test_'.uniqid());
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

    public function test_sha1_unique_index_is_present(): void
    {
        $this->assertTrue($this->uniqueSha1IndexExists());
    }

    public function test_database_unique_index_rejects_second_photo_with_same_sha1_across_classes(): void
    {
        $sha = sha1('unique database bytes');

        Photo::create([
            'id' => '22Buck_007_22BUCK_00001',
            'show_class_id' => '22Buck_007',
            'proof_number' => '22BUCK_00001',
            'file_type' => 'jpg',
            'sha1' => $sha,
        ]);

        $this->expectException(QueryException::class);

        Photo::create([
            'id' => '22Buck_008_22BUCK_00002',
            'show_class_id' => '22Buck_008',
            'proof_number' => '22BUCK_00002',
            'file_type' => 'jpg',
            'sha1' => $sha,
        ]);
    }

    public function test_migration_preflight_aborts_on_existing_non_null_duplicate_hashes(): void
    {
        $sha = sha1('preflight duplicate bytes');

        DB::statement('DROP INDEX IF EXISTS photos_sha1_unique');

        DB::table('photos')->insert([
            [
                'id' => '22Buck_007_22BUCK_00001',
                'show_class_id' => '22Buck_007',
                'proof_number' => '22BUCK_00001',
                'file_type' => 'jpg',
                'sha1' => $sha,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => '22Buck_008_22BUCK_00002',
                'show_class_id' => '22Buck_008',
                'proof_number' => '22BUCK_00002',
                'file_type' => 'jpg',
                'sha1' => $sha,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        try {
            /** @var Migration $migration */
            $migration = require database_path('migrations/2026_09_13_120000_enforce_unique_photo_sha1.php');

            $migration->up();
            $this->fail('Expected the migration preflight to abort on duplicate sha1 values.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('duplicate', strtolower($e->getMessage()));
            $this->assertStringContainsString($sha, $e->getMessage());
            $this->assertSame(2, DB::table('photos')->where('sha1', $sha)->count());
        } finally {
            DB::table('photos')->where('sha1', $sha)->delete();
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS photos_sha1_unique ON photos (sha1)');
        }

    }

    public function test_discovery_hashes_before_insert_and_leaves_no_duplicate_null_row(): void
    {
        $bytes = 'discovered original';
        Photo::create([
            'show_class_id' => '22Buck_007', 'proof_number' => '22BUCK_00001',
            'file_type' => 'jpg', 'sha1' => sha1($bytes),
        ]);
        Storage::disk('fullsize')->put('22Buck/008/originals/22BUCK_00002.jpg', $bytes);
        config(['testing.skip_file_operations' => false]);
        try {
            Image::importPhoto('22BUCK_00002', 'jpg', '22Buck', '008');
            $this->fail('Expected duplicate discovery rejection.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('UNIQUE constraint failed', $e->getMessage());
        }
        $this->assertSame(1, Photo::count());
        $this->assertSame(0, Photo::whereNull('sha1')->count());
        $this->assertSame($bytes, Storage::disk('fullsize')->get('22Buck/008/originals/22BUCK_00002.jpg'));
    }

    public function test_direct_import_cannot_overwrite_existing_identity_with_different_bytes(): void
    {
        Storage::disk('fullsize')->put('22Buck/007/IMG_0001.jpg', 'original bytes');
        (new Image('22Buck/007/IMG_0001.jpg'))->processImage('22BUCK_00001', false);
        Storage::disk('fullsize')->put('22Buck/007/IMG_0002.jpg', 'different bytes');
        try {
            (new Image('22Buck/007/IMG_0002.jpg'))->processImage('22BUCK_00001', false);
            $this->fail('Expected existing identity rejection.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('overwrite different content', $e->getMessage());
        }
        $this->assertSame(1, Photo::count());
        $this->assertSame(sha1('original bytes'), Photo::first()->sha1);
        $this->assertSame('original bytes', Storage::disk('fullsize')->get('22Buck/007/originals/22BUCK_00001.jpg'));
        $this->assertSame('different bytes', Storage::disk('fullsize')->get('22Buck/007/IMG_0002.jpg'));
    }

    public function test_same_content_raw_reimport_reuses_existing_record_without_allocating(): void
    {
        Storage::disk('fullsize')->put('22Buck/007/IMG_0001.jpg', 'retry bytes');
        app(PhotoService::class)->processPhoto('22Buck/007/IMG_0001.jpg', '22BUCK_00001', false, false);

        $this->assertSame(1, Photo::count());

        // Same bytes dropped again under the raw camera name.
        Storage::disk('fullsize')->put('22Buck/007/IMG_0001.jpg', 'retry bytes');
        $result = app(PhotoService::class)->processPhoto('22Buck/007/IMG_0001.jpg', null, false, false);

        $this->assertSame(PhotoImportIdentityResolver::IDEMPOTENT_EXISTING, $result['plan']->decision);
        $this->assertFalse($result['plan']->allocatesNewProofNumber);
        $this->assertNull($result['issue']);
        $this->assertSame(1, Photo::count());
        $this->assertSame('22BUCK_00001', $result['photo']->proof_number);
        $this->assertFalse(Storage::disk('fullsize')->exists('22Buck/007/IMG_0001.jpg'));
    }

    public function test_same_content_same_class_different_number_reuses_existing_proof_number(): void
    {
        Storage::disk('fullsize')->put('22Buck/007/22BUCK_00001.jpg', 'retry numbered bytes');
        app(PhotoService::class)->processPhoto('22Buck/007/22BUCK_00001.jpg', null, false, false);

        Storage::disk('fullsize')->put('22Buck/007/22BUCK_00077.jpg', 'retry numbered bytes');
        $result = app(PhotoService::class)->processPhoto('22Buck/007/22BUCK_00077.jpg', null, false, false);

        $this->assertSame(PhotoImportIdentityResolver::IDEMPOTENT_EXISTING, $result['plan']->decision);
        $this->assertSame(1, Photo::count());
        $this->assertSame('22BUCK_00001', $result['photo']->proof_number);
        $this->assertNull(Photo::find('22Buck_007_22BUCK_00077'));
        $this->assertFalse(Storage::disk('fullsize')->exists('22Buck/007/22BUCK_00077.jpg'));
    }

    public function test_missing_original_is_restored_under_existing_proof_number(): void
    {
        Photo::create([
            'id' => '22Buck_007_22BUCK_00050',
            'show_class_id' => '22Buck_007',
            'proof_number' => '22BUCK_00050',
            'file_type' => 'jpg',
            'sha1' => sha1('restore bytes'),
        ]);

        // No original file on disk; the retry source is the only copy.
        Storage::disk('fullsize')->put('22Buck/007/IMG_9999.jpg', 'restore bytes');

        $result = app(PhotoService::class)->processPhoto('22Buck/007/IMG_9999.jpg', null, false, false);

        $this->assertSame(PhotoImportIdentityResolver::IDEMPOTENT_EXISTING, $result['plan']->decision);
        $this->assertSame(1, Photo::count());
        $this->assertSame('22BUCK_00050', $result['photo']->proof_number);
        $this->assertTrue(Storage::disk('fullsize')->exists('22Buck/007/originals/22BUCK_00050.jpg'));
        $this->assertSame('restore bytes', Storage::disk('fullsize')->get('22Buck/007/originals/22BUCK_00050.jpg'));
        $this->assertFalse(Storage::disk('fullsize')->exists('22Buck/007/IMG_9999.jpg'));
    }

    public function test_process_image_does_not_bury_source_that_is_the_original(): void
    {
        Storage::disk('fullsize')->put('22Buck/007/originals/22BUCK_00050.jpg', 'originals path bytes');

        $image = new Image('22Buck/007/originals/22BUCK_00050.jpg');
        $photo = $image->processImage('22BUCK_00050', false);

        $this->assertSame('22Buck_007_22BUCK_00050', $photo->id);
        $this->assertTrue(Storage::disk('fullsize')->exists('22Buck/007/originals/22BUCK_00050.jpg'));
        $this->assertSame('originals path bytes', Storage::disk('fullsize')->get('22Buck/007/originals/22BUCK_00050.jpg'));
        $this->assertSame([], Storage::disk('fullsize')->allFiles('_graveyard'));
    }

    public function test_process_image_rejects_duplicate_under_different_id_before_any_write(): void
    {
        Photo::create([
            'id' => '22Buck_007_22BUCK_00050',
            'show_class_id' => '22Buck_007',
            'proof_number' => '22BUCK_00050',
            'file_type' => 'jpg',
            'sha1' => sha1('duplicate bytes'),
        ]);

        Storage::disk('fullsize')->put('22Buck/007/IMG_0001.jpg', 'duplicate bytes');

        $image = new Image('22Buck/007/IMG_0001.jpg');

        try {
            $image->processImage('22BUCK_00077', false);
            $this->fail('Expected duplicate content rejection.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('duplicate content', strtolower($e->getMessage()));
        }

        // Existing row intact; no candidate row, original, or archive written; source preserved.
        $this->assertSame(1, Photo::count());
        $this->assertSame(sha1('duplicate bytes'), Photo::find('22Buck_007_22BUCK_00050')->sha1);
        $this->assertNull(Photo::find('22Buck_007_22BUCK_00077'));
        $this->assertTrue(Storage::disk('fullsize')->exists('22Buck/007/IMG_0001.jpg'));
        $this->assertFalse(Storage::disk('fullsize')->exists('22Buck/007/originals/22BUCK_00077.jpg'));
        $this->assertFalse(Storage::disk('archive')->exists('22Buck/007/22BUCK_00077.jpg'));
        $this->assertSame([], Storage::disk('fullsize')->allFiles('_graveyard'));
    }

    public function test_cross_class_duplicate_creates_visible_issue_linked_to_existing_photo(): void
    {
        Photo::create([
            'id' => '22Buck_008_22BUCK_00050',
            'show_class_id' => '22Buck_008',
            'proof_number' => '22BUCK_00050',
            'file_type' => 'jpg',
            'sha1' => sha1('cross class bytes'),
        ]);

        Storage::disk('fullsize')->put('22Buck/007/IMG_0001.jpg', 'cross class bytes');
        $result = app(PhotoService::class)->processPhoto('22Buck/007/IMG_0001.jpg', null, false, false);

        $this->assertNull($result['photo']);
        $this->assertSame(PhotoImportIdentityResolver::DUPLICATE_CONTENT, $result['plan']->decision);
        $this->assertSame(1, Photo::count());

        $issue = $result['issue']->fresh();
        $this->assertSame(PhotoIssue::TYPE_DUPLICATE_CONTENT, $issue->issue_type);
        $this->assertSame('22Buck_008_22BUCK_00050', $issue->existing_photo_id);
        $this->assertSame('22BUCK_00050', $issue->existing_proof_number);
        $this->assertTrue(Storage::disk('fullsize')->exists($issue->quarantine_path));
    }

    private function uniqueSha1IndexExists(): bool
    {
        return collect(DB::select("PRAGMA index_list('photos')"))
            ->contains(fn ($row) => ($row->name ?? null) === 'photos_sha1_unique');
    }
}
