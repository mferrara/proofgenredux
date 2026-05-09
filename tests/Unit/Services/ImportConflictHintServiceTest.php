<?php

namespace Tests\Unit\Services;

use App\Models\Photo;
use App\Models\PhotoMetadata;
use App\Models\Show;
use App\Models\ShowClass;
use App\Services\ImportConflictHintService;
use App\Services\PhotoImportIdentityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportConflictHintServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        config(['testing.skip_file_operations' => true]);

        $this->tempPath = storage_path('app/hint_test_'.uniqid());
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
            ShowClass::create(['id' => '22Buck_121', 'show_id' => '22Buck', 'name' => '121']);
            ShowClass::create(['id' => '22Buck_124', 'show_id' => '22Buck', 'name' => '124']);
            ShowClass::create(['id' => '22Buck_200', 'show_id' => '22Buck', 'name' => '200']);
        });
    }

    protected function tearDown(): void
    {
        if (isset($this->tempPath) && File::exists($this->tempPath)) {
            File::deleteDirectory($this->tempPath);
        }

        parent::tearDown();
    }

    private function makePhoto(string $classId, string $proofNumber, string $sha1, ?string $originalFilename, ?string $exifTimestamp = null): Photo
    {
        $photo = Photo::create([
            'id' => $classId.'_'.$proofNumber,
            'show_class_id' => $classId,
            'proof_number' => $proofNumber,
            'file_type' => 'jpg',
            'sha1' => $sha1,
            'original_filename' => $originalFilename,
        ]);

        PhotoMetadata::create([
            'photo_id' => $photo->id,
            'exif_timestamp' => $exifTimestamp,
        ]);

        return $photo->fresh();
    }

    private function resolvePlan(string $sourceRelativePath): \App\Services\PhotoImportPlan
    {
        return app(PhotoImportIdentityResolver::class)->resolve($sourceRelativePath, '22Buck', explode('/', $sourceRelativePath)[1]);
    }

    public function test_ordinal_fit_when_incoming_sits_within_range(): void
    {
        // Existing duplicate-by-content lives in 121 with a tight ordinal cluster around 2625-2640.
        $sharedSha = sha1('shared bytes A');
        $this->makePhoto('22Buck_121', '22BUCK_00045', $sharedSha, 'IMG_02631.jpg');
        $this->makePhoto('22Buck_121', '22BUCK_00040', sha1('p1'), 'IMG_02625.jpg');
        $this->makePhoto('22Buck_121', '22BUCK_00050', sha1('p2'), 'IMG_02640.jpg');
        $this->makePhoto('22Buck_121', '22BUCK_00046', sha1('p3'), 'IMG_02632.jpg');

        // Target class 124 has ordinals 2700-2800 — incoming 2631 will be an outlier here.
        $this->makePhoto('22Buck_124', '22BUCK_00100', sha1('q1'), 'IMG_02700.jpg');
        $this->makePhoto('22Buck_124', '22BUCK_00120', sha1('q2'), 'IMG_02800.jpg');

        Storage::disk('fullsize')->put('22Buck/124/IMG_02631.jpg', 'shared bytes A');

        $plan = $this->resolvePlan('22Buck/124/IMG_02631.jpg');
        $this->assertSame(PhotoImportIdentityResolver::DUPLICATE_CONTENT, $plan->decision);

        $hints = app(ImportConflictHintService::class)->hintsFor($plan);

        $this->assertSame('IMG_02631.jpg', $hints['incoming']['original_filename']);
        $this->assertSame(2631, $hints['incoming']['ordinal']);

        $candidates = $hints['candidates'];
        $this->assertArrayHasKey('22Buck_121', $candidates);
        $this->assertArrayHasKey('22Buck_124', $candidates);

        // 121 should fit well (within range 2625..2640)
        $this->assertTrue($candidates['22Buck_121']['ordinal_fit']['within_range']);
        // IMG_02631 already lives in 121 (this is the duplicate-content match), so it's the exact nearest match both sides.
        $this->assertSame(2631, $candidates['22Buck_121']['ordinal_fit']['nearest_before']['ordinal']);
        $this->assertSame(2631, $candidates['22Buck_121']['ordinal_fit']['nearest_after']['ordinal']);
        $this->assertSame(2625, $candidates['22Buck_121']['ordinal_fit']['min']);
        $this->assertSame(2640, $candidates['22Buck_121']['ordinal_fit']['max']);

        // 124 should be outside range (2700..2800)
        $this->assertFalse($candidates['22Buck_124']['ordinal_fit']['within_range']);
        $this->assertNull($candidates['22Buck_124']['ordinal_fit']['nearest_before']);
        $this->assertSame(2700, $candidates['22Buck_124']['ordinal_fit']['nearest_after']['ordinal']);
    }

    public function test_ordinal_fit_null_when_existing_class_lacks_original_filenames(): void
    {
        // Existing photo in 121 lacks original_filename (legacy row).
        $sharedSha = sha1('legacy shared');
        $this->makePhoto('22Buck_121', '22BUCK_00010', $sharedSha, null);

        // Target class 124 has parseable siblings.
        $this->makePhoto('22Buck_124', '22BUCK_00050', sha1('s1'), 'IMG_02500.jpg');

        Storage::disk('fullsize')->put('22Buck/124/IMG_02631.jpg', 'legacy shared');

        $plan = $this->resolvePlan('22Buck/124/IMG_02631.jpg');
        $hints = app(ImportConflictHintService::class)->hintsFor($plan);

        $this->assertNull($hints['candidates']['22Buck_121']['ordinal_fit']);
        $this->assertNotEmpty($hints['candidates']['22Buck_121']['notes']);
        $this->assertNotNull($hints['candidates']['22Buck_124']['ordinal_fit']);
    }

    public function test_multiple_candidate_classes_returned_correctly_keyed(): void
    {
        $sharedSha = sha1('multi candidate bytes');
        $this->makePhoto('22Buck_200', '22BUCK_00001', $sharedSha, 'IMG_05000.jpg');
        $this->makePhoto('22Buck_124', '22BUCK_00099', sha1('other'), 'IMG_03000.jpg');

        Storage::disk('fullsize')->put('22Buck/124/IMG_03050.jpg', 'multi candidate bytes');

        $plan = $this->resolvePlan('22Buck/124/IMG_03050.jpg');
        $hints = app(ImportConflictHintService::class)->hintsFor($plan);

        $this->assertEqualsCanonicalizing(
            ['22Buck_124', '22Buck_200'],
            array_keys($hints['candidates']),
        );
        $this->assertSame('22Buck_124', $hints['candidates']['22Buck_124']['show_class_id']);
        $this->assertSame('22Buck_200', $hints['candidates']['22Buck_200']['show_class_id']);
    }

    public function test_service_does_not_mutate_filesystem_or_database(): void
    {
        $sharedSha = sha1('immutable bytes');
        $this->makePhoto('22Buck_121', '22BUCK_00045', $sharedSha, 'IMG_02631.jpg');
        Storage::disk('fullsize')->put('22Buck/124/IMG_02631.jpg', 'immutable bytes');

        $plan = $this->resolvePlan('22Buck/124/IMG_02631.jpg');

        $beforeFiles = Storage::disk('fullsize')->allFiles();
        $beforePhotos = Photo::query()->orderBy('id')->get()->toArray();
        $beforeMeta = PhotoMetadata::query()->orderBy('photo_id')->get()->toArray();

        app(ImportConflictHintService::class)->hintsFor($plan);

        $this->assertSame($beforeFiles, Storage::disk('fullsize')->allFiles());
        $this->assertEquals($beforePhotos, Photo::query()->orderBy('id')->get()->toArray());
        $this->assertEquals($beforeMeta, PhotoMetadata::query()->orderBy('photo_id')->get()->toArray());
    }

    public function test_capture_time_null_when_source_lacks_exif(): void
    {
        $sharedSha = sha1('no exif bytes');
        $this->makePhoto('22Buck_121', '22BUCK_00045', $sharedSha, 'IMG_02631.jpg');

        Storage::disk('fullsize')->put('22Buck/124/IMG_02631.jpg', 'no exif bytes');

        $plan = $this->resolvePlan('22Buck/124/IMG_02631.jpg');
        $hints = app(ImportConflictHintService::class)->hintsFor($plan);

        $this->assertNull($hints['incoming']['capture_time']);
        $this->assertNull($hints['candidates']['22Buck_121']['time_fit']);
        $this->assertContains('incoming source has no EXIF capture time', $hints['candidates']['22Buck_121']['notes']);
    }
}
