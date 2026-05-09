<?php

namespace Tests\Unit\Services;

use App\Models\Photo;
use App\Models\Show;
use App\Models\ShowClass;
use App\Services\PhotoImportIdentityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhotoImportIdentityResolverTest extends TestCase
{
    use RefreshDatabase;

    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        config(['testing.skip_file_operations' => true]);

        $this->tempPath = storage_path('app/resolver_test_'.uniqid());
        File::makeDirectory($this->tempPath.'/fullsize', 0755, true);
        config(['filesystems.disks.fullsize' => [
            'driver' => 'local',
            'root' => $this->tempPath.'/fullsize',
            'throw' => true,
        ]]);
        config(['proofgen.fullsize_home_dir' => $this->tempPath.'/fullsize']);
        Storage::forgetDisk('fullsize');

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

    public function test_extract_embedded_proof_number_recognizes_show_pattern(): void
    {
        $resolver = app(PhotoImportIdentityResolver::class);

        $this->assertSame('22BUCK_00093', $resolver->extractEmbeddedProofNumber('22BUCK_00093.jpg', '22Buck'));
        $this->assertSame('22BUCK_00001', $resolver->extractEmbeddedProofNumber('22buck_00001.JPG', '22Buck'));
    }

    public function test_extract_embedded_proof_number_rejects_raw_camera_names(): void
    {
        $resolver = app(PhotoImportIdentityResolver::class);

        $this->assertNull($resolver->extractEmbeddedProofNumber('IMG_02630.jpg', '22Buck'));
        $this->assertNull($resolver->extractEmbeddedProofNumber('DSC00123.jpg', '22Buck'));
        $this->assertNull($resolver->extractEmbeddedProofNumber('22BUCK_999.jpg', '22Buck'));
        $this->assertNull($resolver->extractEmbeddedProofNumber('22BUCK_000932.jpg', '22Buck'));
        $this->assertNull($resolver->extractEmbeddedProofNumber('22BUCKER_00093.jpg', '22Buck'));
    }

    public function test_raw_unseen_filename_imports_new_with_allocated_proof_number(): void
    {
        Storage::disk('fullsize')->put('22Buck/007/IMG_0001.jpg', 'fresh bytes');

        $plan = app(PhotoImportIdentityResolver::class)->resolve('22Buck/007/IMG_0001.jpg', '22Buck', '007');

        $this->assertSame(PhotoImportIdentityResolver::IMPORT_NEW, $plan->decision);
        $this->assertTrue($plan->allocatesNewProofNumber);
        $this->assertNull($plan->intendedProofNumber);
        $this->assertFalse($plan->filenameIsNumberedForShow);
        $this->assertSame('IMG_0001.jpg', $plan->originalFilename);
        $this->assertSame(sha1('fresh bytes'), $plan->sha1);
    }

    public function test_already_numbered_unseen_filename_imports_new_without_allocating(): void
    {
        Storage::disk('fullsize')->put('22Buck/007/22BUCK_00093.jpg', 'rehydrated bytes');

        $plan = app(PhotoImportIdentityResolver::class)->resolve('22Buck/007/22BUCK_00093.jpg', '22Buck', '007');

        $this->assertSame(PhotoImportIdentityResolver::IMPORT_NEW, $plan->decision);
        $this->assertFalse($plan->allocatesNewProofNumber);
        $this->assertSame('22BUCK_00093', $plan->intendedProofNumber);
        $this->assertTrue($plan->filenameIsNumberedForShow);
    }

    public function test_same_sha_same_proof_same_class_is_idempotent(): void
    {
        Storage::disk('fullsize')->put('22Buck/007/22BUCK_00093.jpg', 'same bytes');
        Photo::create([
            'id' => '22Buck_007_22BUCK_00093',
            'show_class_id' => '22Buck_007',
            'proof_number' => '22BUCK_00093',
            'file_type' => 'jpg',
            'sha1' => sha1('same bytes'),
        ]);

        $plan = app(PhotoImportIdentityResolver::class)->resolve('22Buck/007/22BUCK_00093.jpg', '22Buck', '007');

        $this->assertSame(PhotoImportIdentityResolver::IDEMPOTENT_EXISTING, $plan->decision);
        $this->assertFalse($plan->allocatesNewProofNumber);
        $this->assertSame('22Buck_007_22BUCK_00093', $plan->existingByContent->id);
    }

    public function test_same_sha_different_proof_is_duplicate_content(): void
    {
        Storage::disk('fullsize')->put('22Buck/007/22BUCK_00093.jpg', 'shared bytes');
        Photo::create([
            'id' => '22Buck_007_22BUCK_00050',
            'show_class_id' => '22Buck_007',
            'proof_number' => '22BUCK_00050',
            'file_type' => 'jpg',
            'sha1' => sha1('shared bytes'),
        ]);

        $plan = app(PhotoImportIdentityResolver::class)->resolve('22Buck/007/22BUCK_00093.jpg', '22Buck', '007');

        $this->assertSame(PhotoImportIdentityResolver::DUPLICATE_CONTENT, $plan->decision);
        $this->assertSame('22BUCK_00050', $plan->existingByContent->proof_number);
    }

    public function test_same_proof_different_sha_is_proof_collision(): void
    {
        Storage::disk('fullsize')->put('22Buck/007/22BUCK_00093.jpg', 'incoming bytes');
        Photo::create([
            'id' => '22Buck_007_22BUCK_00093',
            'show_class_id' => '22Buck_007',
            'proof_number' => '22BUCK_00093',
            'file_type' => 'jpg',
            'sha1' => sha1('original bytes'),
        ]);

        $plan = app(PhotoImportIdentityResolver::class)->resolve('22Buck/007/22BUCK_00093.jpg', '22Buck', '007');

        $this->assertSame(PhotoImportIdentityResolver::PROOF_COLLISION, $plan->decision);
        $this->assertSame(sha1('original bytes'), $plan->existingByProofNumber->sha1);
    }

    public function test_raw_filename_with_known_sha_is_duplicate_content(): void
    {
        Storage::disk('fullsize')->put('22Buck/007/IMG_0001.jpg', 'previously imported bytes');
        Photo::create([
            'id' => '22Buck_008_22BUCK_00010',
            'show_class_id' => '22Buck_008',
            'proof_number' => '22BUCK_00010',
            'file_type' => 'jpg',
            'sha1' => sha1('previously imported bytes'),
        ]);

        $plan = app(PhotoImportIdentityResolver::class)->resolve('22Buck/007/IMG_0001.jpg', '22Buck', '007');

        $this->assertSame(PhotoImportIdentityResolver::DUPLICATE_CONTENT, $plan->decision);
        $this->assertSame('22Buck_008', $plan->existingByContent->show_class_id);
    }

    public function test_resolve_does_not_mutate_filesystem_or_database(): void
    {
        Storage::disk('fullsize')->put('22Buck/007/IMG_0001.jpg', 'untouched bytes');

        $beforeFiles = Storage::disk('fullsize')->allFiles();
        $beforePhotoCount = Photo::count();

        app(PhotoImportIdentityResolver::class)->resolve('22Buck/007/IMG_0001.jpg', '22Buck', '007');

        $this->assertSame($beforeFiles, Storage::disk('fullsize')->allFiles());
        $this->assertSame($beforePhotoCount, Photo::count());
    }
}
