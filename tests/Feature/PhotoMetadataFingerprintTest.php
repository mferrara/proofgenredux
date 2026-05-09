<?php

namespace Tests\Feature;

use App\Models\Photo;
use App\Models\PhotoIssue;
use App\Models\PhotoMetadata;
use App\Models\Show;
use App\Models\ShowClass;
use App\Services\PhotoImportIdentityResolver;
use App\Services\PhotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhotoMetadataFingerprintTest extends TestCase
{
    use RefreshDatabase;

    private string $tempPath;

    private string $sampleBytes;

    protected function setUp(): void
    {
        parent::setUp();

        $fixture = base_path('storage/sample_images/22Buck/007/22BUCK_00093.jpg');
        if (! is_file($fixture)) {
            $this->markTestSkipped('Sample image fixture missing.');
        }
        $this->sampleBytes = file_get_contents($fixture);

        ini_set('memory_limit', '512M');

        config(['testing.skip_file_operations' => true]);
        config(['proofgen.rename_files' => true]);
        config(['proofgen.archive_enabled' => true]);

        $this->tempPath = storage_path('app/metadata_fingerprint_test_'.uniqid());
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
        });
    }

    protected function tearDown(): void
    {
        if (isset($this->tempPath) && File::exists($this->tempPath)) {
            File::deleteDirectory($this->tempPath);
        }
        parent::tearDown();
    }

    public function test_resolver_attaches_capture_fingerprint_to_plan(): void
    {
        Storage::disk('fullsize')->put('22Buck/007/22BUCK_00093.jpg', $this->sampleBytes);

        $plan = app(PhotoImportIdentityResolver::class)->resolve('22Buck/007/22BUCK_00093.jpg', '22Buck', '007');

        $this->assertNotEmpty($plan->captureFingerprint);
        $this->assertArrayHasKey('camera_make', $plan->captureFingerprint);
        $this->assertArrayHasKey('camera_model', $plan->captureFingerprint);
        $this->assertArrayHasKey('exif_timestamp', $plan->captureFingerprint);
        $this->assertNotNull($plan->sourceMtime);
    }

    public function test_fingerprint_extracts_well_known_fields_from_exif_array(): void
    {
        // Synthetic EXIF that mirrors a high-end DSLR payload.
        $exif = [
            'IFD0' => [
                'Make' => 'Canon',
                'Model' => 'Canon EOS R5',
                'Software' => 'Adobe Photoshop Lightroom Classic 13.0',
            ],
            'EXIF' => [
                'BodySerialNumber' => '062029000123',
                'LensMake' => 'Canon',
                'LensModel' => 'RF 70-200mm F2.8 L IS USM',
                'LensSerialNumber' => '7700001234',
                'ImageUniqueID' => 'ABCDEF0123456789ABCDEF0123456789',
                'SubSecTimeOriginal' => '47',
                'ColorSpace' => 1,
                'WhiteBalance' => 0,
                'ExposureProgram' => 4,
                'MeteringMode' => 5,
                'Flash' => 16,
                'FocalLengthIn35mmFilm' => 200,
            ],
            'GPS' => [
                'GPSLatitude' => ['39/1', '57/1', '174/10'],
                'GPSLatitudeRef' => 'N',
                'GPSLongitude' => ['83/1', '0/1', '90/10'],
                'GPSLongitudeRef' => 'W',
                'GPSAltitude' => '2400/10',
                'GPSAltitudeRef' => 0,
            ],
        ];

        $forensic = PhotoMetadata::extractForensicFields($exif);

        $this->assertSame('062029000123', $forensic['body_serial_number']);
        $this->assertSame('Canon', $forensic['lens_make']);
        $this->assertSame('RF 70-200mm F2.8 L IS USM', $forensic['lens_model']);
        $this->assertSame('7700001234', $forensic['lens_serial_number']);
        $this->assertSame('ABCDEF0123456789ABCDEF0123456789', $forensic['image_unique_id']);
        $this->assertSame('47', $forensic['subsec_time_original']);
        $this->assertSame('sRGB', $forensic['color_space']);
        $this->assertSame('Auto', $forensic['white_balance']);
        $this->assertSame('Shutter Priority', $forensic['exposure_program']);
        $this->assertSame('Multi-segment', $forensic['metering_mode']);
        $this->assertSame('Not fired', $forensic['flash']);
        $this->assertEqualsWithDelta(200.0, $forensic['focal_length_35mm'], 0.01);
        // 39° 57' 17.4" N → 39.954833...
        $this->assertEqualsWithDelta(39.9548333, $forensic['gps_latitude'], 0.0001);
        // 83° 0' 9.0" W → -83.0025
        $this->assertEqualsWithDelta(-83.0025, $forensic['gps_longitude'], 0.0001);
        $this->assertSame(240.0, $forensic['gps_altitude']);
    }

    public function test_fingerprint_returns_empty_array_for_non_exif_file(): void
    {
        Storage::disk('fullsize')->put('22Buck/007/notreal.jpg', 'definitely not a JPG');
        $absolute = $this->tempPath.'/fullsize/22Buck/007/notreal.jpg';

        $this->assertSame([], PhotoMetadata::fingerprintFromFile($absolute));
    }

    public function test_issue_evidence_includes_capture_fingerprint(): void
    {
        // Pre-existing photo with same SHA in another class to trigger DUPLICATE_CONTENT.
        ShowClass::withoutEvents(function () {
            ShowClass::create(['id' => '22Buck_008', 'show_id' => '22Buck', 'name' => '008']);
        });
        Photo::create([
            'id' => '22Buck_008_22BUCK_00050',
            'show_class_id' => '22Buck_008',
            'proof_number' => '22BUCK_00050',
            'file_type' => 'jpg',
            'sha1' => sha1($this->sampleBytes),
        ]);

        Storage::disk('fullsize')->put('22Buck/007/22BUCK_00093.jpg', $this->sampleBytes);
        $result = app(PhotoService::class)->processPhoto('22Buck/007/22BUCK_00093.jpg', null, false, false);

        $issue = $result['issue']->fresh();
        $this->assertSame(PhotoIssue::TYPE_DUPLICATE_CONTENT, $issue->issue_type);
        $this->assertArrayHasKey('capture_fingerprint', $issue->evidence);
        $this->assertArrayHasKey('source_mtime', $issue->evidence);
        $this->assertIsArray($issue->evidence['capture_fingerprint']);
        // Sample images do carry EXIF so we expect at least these keys to be present.
        $this->assertArrayHasKey('camera_make', $issue->evidence['capture_fingerprint']);
        $this->assertArrayHasKey('exif_timestamp', $issue->evidence['capture_fingerprint']);
    }
}
