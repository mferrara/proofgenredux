<?php

namespace Tests\Feature;

use App\Jobs\Photo\GenerateHighresImage;
use App\Jobs\Photo\GenerateThumbnails;
use App\Jobs\Photo\GenerateWebImage;
use App\Models\Photo;
use App\Models\Show;
use App\Models\ShowClass;
use App\Services\PhotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression coverage for the import-recovery paths around the 31.5MB JPEG OOM.
 *
 * The crash held the source bytes + verification buffers and then re-read the
 * whole original inside Photo::createMetadataRecord(). These tests pin the
 * recovery contract without ever loading a multi-megabyte fixture:
 *
 *  - metadata creation uses filesize(), never Photo::getFileContents();
 *  - an idempotent same-class retry repairs a half-imported photo in place
 *    (same id/proof number, no Redis proof-number allocation), buries the retry
 *    source, and queues only the derivatives that are actually missing;
 *  - the product toggles and dispatchJobs=false stay respected on that path.
 *
 * All fixtures are tiny GD-generated JPEGs written to a Storage fake; no test
 * reads sample_images, the operator's directories, or any remote service.
 */
class PhotoImportRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const SHOW = 'RECOVERY';

    private const CLASS_NAME = '101';

    private const SHOW_CLASS = 'RECOVERY_101';

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('The GD extension is required to generate synthetic JPEG fixtures.');
        }

        config([
            'testing.skip_file_operations' => true,
            'proofgen.archive_enabled' => false,
            'proofgen.rename_files' => true,
            'proofgen.graveyard.path' => '_graveyard',
            'proofgen.generate_web_images.enabled' => true,
            'proofgen.generate_highres_images.enabled' => true,
        ]);

        Storage::fake('fullsize');
        config(['proofgen.fullsize_home_dir' => Storage::disk('fullsize')->path('')]);

        Show::withoutEvents(fn () => Show::create(['id' => self::SHOW, 'name' => self::SHOW]));
        ShowClass::withoutEvents(fn () => ShowClass::create([
            'id' => self::SHOW_CLASS,
            'show_id' => self::SHOW,
            'name' => self::CLASS_NAME,
        ]));
    }

    public function test_create_metadata_record_never_reads_file_contents(): void
    {
        $bytes = $this->syntheticJpeg(24, 18);
        $proofNumber = '00006';
        $originalPath = self::SHOW.'/'.self::CLASS_NAME.'/originals/'.$proofNumber.'.jpg';
        Storage::disk('fullsize')->put($originalPath, $bytes);

        // A real model with getFileContents() trapped: if createMetadataRecord()
        // ever falls back to reading the whole original, the test fails loudly.
        $photo = new class extends Photo
        {
            public bool $fileContentsRead = false;

            public function getFileContents(): ?string
            {
                $this->fileContentsRead = true;

                throw new \RuntimeException('createMetadataRecord() must not call getFileContents().');
            }
        };
        $photo->show_class_id = self::SHOW_CLASS;
        $photo->proof_number = $proofNumber;
        $photo->file_type = 'jpg';
        $photo->sha1 = sha1($bytes);
        $photo->save();

        $metadata = $photo->createMetadataRecord();

        $this->assertFalse($photo->fileContentsRead, 'createMetadataRecord() read the full JPEG contents.');
        $this->assertTrue($photo->metadata()->exists());
        $this->assertSame(strlen($bytes), (int) $metadata->fresh()->file_size);
    }

    public function test_idempotent_retry_recovers_partial_photo_and_queues_all_derivatives(): void
    {
        Bus::fake();
        Redis::shouldReceive('client')->never();

        $bytes = $this->syntheticJpeg();
        $photo = $this->createPartialPhoto('00001', $bytes);
        $this->assertFalse($photo->metadata()->exists());
        $this->assertSame(1, Photo::count());

        // The same bytes are re-dropped into the class folder (operator retry).
        Storage::disk('fullsize')->put(self::SHOW.'/'.self::CLASS_NAME.'/IMG_00001.jpg', $bytes);

        // Worker died after the row/original were written; recovery must repair.
        config(['testing.skip_file_operations' => false]);

        $result = app(PhotoService::class)->processPhoto(self::SHOW.'/'.self::CLASS_NAME.'/IMG_00001.jpg');
        $recovered = $result['photo']->fresh();

        // Identity and proof number are preserved; no second row / allocation.
        $this->assertSame($photo->id, $recovered->id);
        $this->assertSame('00001', $recovered->proof_number);
        $this->assertSame(1, Photo::count());

        // Missing metadata was repaired from the on-disk original.
        $this->assertTrue($recovered->metadata()->exists());
        $this->assertSame(strlen($bytes), (int) $recovered->metadata->file_size);

        // The retry source is buried (it is not the originals copy).
        $this->assertFalse(Storage::disk('fullsize')->exists(self::SHOW.'/'.self::CLASS_NAME.'/IMG_00001.jpg'));
        $graveyard = Storage::disk('fullsize')->allFiles('_graveyard');
        $sidecars = array_values(array_filter($graveyard, fn (string $path) => str_ends_with($path, '.jpg.json')));
        $this->assertCount(1, $sidecars);
        $sidecar = json_decode(Storage::disk('fullsize')->get($sidecars[0]), true);
        $this->assertSame('post_import_source', $sidecar['reason']);
        $this->assertTrue($sidecar['context']['idempotent_retry']);
        $this->assertSame($recovered->id, $sidecar['context']['photo_id']);
        $this->assertSame('IMG_00001.jpg', $sidecar['context']['original_filename']);

        // All three outputs are missing, so all three jobs are queued once each.
        Bus::assertDispatchedTimes(GenerateThumbnails::class, 1);
        Bus::assertDispatchedTimes(GenerateWebImage::class, 1);
        Bus::assertDispatchedTimes(GenerateHighresImage::class, 1);

        Bus::assertDispatched(GenerateThumbnails::class, function (GenerateThumbnails $job) use ($recovered) {
            return $job->photo_id === $recovered->id
                && $job->proofs_destination_path === '/proofs/'.self::SHOW.'/'.self::CLASS_NAME
                && $job->queue === 'thumbnails';
        });
        Bus::assertDispatched(GenerateWebImage::class, function (GenerateWebImage $job) use ($recovered) {
            return $job->photo_id === $recovered->id
                && $job->web_destination_path === 'web_images/'.self::SHOW.'/'.self::CLASS_NAME
                && $job->queue === 'thumbnails';
        });
        Bus::assertDispatched(GenerateHighresImage::class, function (GenerateHighresImage $job) use ($recovered) {
            return $job->photo_id === $recovered->id
                && $job->highres_destination_path === 'highres_images/'.self::SHOW.'/'.self::CLASS_NAME
                && $job->queue === 'thumbnails';
        });
    }

    public function test_new_import_regenerates_derivatives_left_by_an_old_record(): void
    {
        Bus::fake();
        $bytes = $this->syntheticJpeg();
        $proofNumber = 'RECOVERY_00007';
        Storage::disk('fullsize')->put('RECOVERY/101/IMG_00007.jpg', $bytes);
        foreach (config('proofgen.thumbnails') as $size) {
            Storage::disk('fullsize')->put('proofs/RECOVERY/101/'.$proofNumber.$size['suffix'].'.jpg', $bytes);
        }
        Storage::disk('fullsize')->put('web_images/RECOVERY/101/'.$proofNumber.config('proofgen.web_images.suffix').'.jpg', $bytes);
        Storage::disk('fullsize')->put('highres_images/RECOVERY/101/'.$proofNumber.config('proofgen.highres_images.suffix').'.jpg', $bytes);
        config(['testing.skip_file_operations' => false]);

        $result = app(PhotoService::class)->processPhoto('RECOVERY/101/IMG_00007.jpg', $proofNumber);
        // Discovery sees the old files. A new/replacement import must still
        // schedule fresh outputs rather than treating those files as current.
        $this->assertNotNull($result['photo']->fresh()->proofs_generated_at);
        Bus::assertDispatchedTimes(GenerateThumbnails::class, 1);
        Bus::assertDispatchedTimes(GenerateWebImage::class, 1);
        Bus::assertDispatchedTimes(GenerateHighresImage::class, 1);
    }

    public function test_idempotent_retry_with_complete_outputs_dispatches_nothing(): void
    {
        Bus::fake();

        $bytes = $this->syntheticJpeg();
        $photo = $this->createPartialPhoto('00002', $bytes);
        $photo->createMetadataRecord();
        $photo->forceFill([
            'proofs_generated_at' => now(),
            'web_image_generated_at' => now(),
            'highres_image_generated_at' => now(),
        ])->save();

        Storage::disk('fullsize')->put(self::SHOW.'/'.self::CLASS_NAME.'/IMG_00002.jpg', $bytes);
        config(['testing.skip_file_operations' => false]);

        $result = app(PhotoService::class)->processPhoto(self::SHOW.'/'.self::CLASS_NAME.'/IMG_00002.jpg');

        $this->assertSame($photo->id, $result['photo']->id);
        $this->assertSame(1, Photo::count());
        Bus::assertNothingDispatched();
    }

    public function test_idempotent_retry_queues_only_missing_derivatives(): void
    {
        Bus::fake();

        $bytes = $this->syntheticJpeg();
        $photo = $this->createPartialPhoto('00003', $bytes);
        $photo->createMetadataRecord();
        // Proofs already generated; web + highres still missing.
        $photo->forceFill(['proofs_generated_at' => now()])->save();

        Storage::disk('fullsize')->put(self::SHOW.'/'.self::CLASS_NAME.'/IMG_00003.jpg', $bytes);
        config(['testing.skip_file_operations' => false]);

        app(PhotoService::class)->processPhoto(self::SHOW.'/'.self::CLASS_NAME.'/IMG_00003.jpg');

        Bus::assertNotDispatched(GenerateThumbnails::class);
        Bus::assertDispatchedTimes(GenerateWebImage::class, 1);
        Bus::assertDispatchedTimes(GenerateHighresImage::class, 1);

        Bus::assertDispatched(GenerateWebImage::class, function (GenerateWebImage $job) use ($photo) {
            return $job->photo_id === $photo->id
                && $job->web_destination_path === 'web_images/'.self::SHOW.'/'.self::CLASS_NAME
                && $job->queue === 'thumbnails';
        });
        Bus::assertDispatched(GenerateHighresImage::class, function (GenerateHighresImage $job) use ($photo) {
            return $job->photo_id === $photo->id
                && $job->highres_destination_path === 'highres_images/'.self::SHOW.'/'.self::CLASS_NAME
                && $job->queue === 'thumbnails';
        });
    }

    public function test_idempotent_retry_respects_disabled_web_and_highres_switches(): void
    {
        Bus::fake();
        config([
            'proofgen.generate_web_images.enabled' => false,
            'proofgen.generate_highres_images.enabled' => false,
        ]);

        $bytes = $this->syntheticJpeg();
        $this->createPartialPhoto('00004', $bytes);
        Storage::disk('fullsize')->put(self::SHOW.'/'.self::CLASS_NAME.'/IMG_00004.jpg', $bytes);
        config(['testing.skip_file_operations' => false]);

        app(PhotoService::class)->processPhoto(self::SHOW.'/'.self::CLASS_NAME.'/IMG_00004.jpg');

        Bus::assertDispatchedTimes(GenerateThumbnails::class, 1);
        Bus::assertNotDispatched(GenerateWebImage::class);
        Bus::assertNotDispatched(GenerateHighresImage::class);
    }

    public function test_idempotent_retry_with_dispatch_jobs_false_recovers_without_dispatching(): void
    {
        Bus::fake();

        $bytes = $this->syntheticJpeg();
        $photo = $this->createPartialPhoto('00005', $bytes);
        Storage::disk('fullsize')->put(self::SHOW.'/'.self::CLASS_NAME.'/IMG_00005.jpg', $bytes);
        config(['testing.skip_file_operations' => false]);

        $result = app(PhotoService::class)->processPhoto(
            self::SHOW.'/'.self::CLASS_NAME.'/IMG_00005.jpg',
            dispatchJobs: false,
        );

        Bus::assertNothingDispatched();
        $this->assertTrue($result['photo']->fresh()->metadata()->exists());
        $this->assertFalse(Storage::disk('fullsize')->exists(self::SHOW.'/'.self::CLASS_NAME.'/IMG_00005.jpg'));
        $this->assertSame(1, Photo::count());
    }

    /**
     * A real, valid, tiny JPEG generated locally with GD (no fixture files).
     */
    private function syntheticJpeg(int $width = 40, int $height = 30, int $r = 30, int $g = 120, int $b = 200): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, $r, $g, $b));

        ob_start();
        imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /**
     * Simulate the crash state: original written + photos row inserted, but the
     * created hook never finished (metadata missing, derivative stamps null).
     */
    private function createPartialPhoto(string $proofNumber, string $bytes): Photo
    {
        Storage::disk('fullsize')->put(
            self::SHOW.'/'.self::CLASS_NAME.'/originals/'.$proofNumber.'.jpg',
            $bytes,
        );

        return Photo::create([
            'show_class_id' => self::SHOW_CLASS,
            'proof_number' => $proofNumber,
            'file_type' => 'jpg',
            'sha1' => sha1($bytes),
        ]);
    }
}
