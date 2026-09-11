<?php

namespace Tests\Feature;

use App\Jobs\Ferraraphoto\EnsureFerraraphotoShow;
use App\Jobs\Photo\GenerateThumbnails;
use App\Jobs\Photo\GenerateWebImage;
use App\Jobs\Photo\ImportPhoto;
use App\Jobs\ShowClass\PushPhotoMetadata;
use App\Jobs\ShowClass\UploadDerivedFiles;
use App\Jobs\ShowClass\UploadHighresImages;
use App\Jobs\ShowClass\UploadProofs;
use App\Jobs\ShowClass\UploadWebImages;
use App\Models\Show;
use App\Models\ShowClass;
use App\Services\PathResolver;
use App\Services\PhotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Real image processing coverage (import + archive + thumbnails + web images).
 *
 * Isolation contract:
 *  - every Storage disk used here lives under a unique per-test temp directory;
 *  - outbound upload / remote-metadata jobs are selectively faked via Bus so the
 *    real import + derivative generation work runs while nothing touches SFTP;
 *  - fixtures are generated locally instead of reading storage/sample_images or
 *    downloading from the S3 sample bucket.
 */
class RealImageProcessingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Outbound jobs that must never execute from these tests.
     */
    protected const OUTBOUND_JOBS = [
        UploadProofs::class,
        UploadWebImages::class,
        UploadHighresImages::class,
        UploadDerivedFiles::class,
        PushPhotoMetadata::class,
        EnsureFerraraphotoShow::class,
    ];

    protected string $tempPath;

    protected string $show = 'TestShow2024';

    protected string $class = 'TestClass';

    protected PhotoService $photoService;

    protected PathResolver $pathResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pathResolver = new PathResolver;

        // Unique per-test root for every disk this test writes to. Nothing is
        // shared with the operator's real image directories or with other tests.
        $this->tempPath = storage_path('app/temp_test_'.uniqid());
        File::makeDirectory($this->tempPath, 0755, true);

        config([
            'filesystems.disks.fullsize' => [
                'driver' => 'local',
                'root' => $this->tempPath.'/fullsize',
                'throw' => true,
            ],
            'filesystems.disks.archive' => [
                'driver' => 'local',
                'root' => $this->tempPath.'/archive',
                'throw' => true,
            ],
            'filesystems.disks.sample_images' => [
                'driver' => 'local',
                'root' => $this->tempPath.'/sample_images',
                'throw' => false,
            ],
            'filesystems.disks.sample_images_bucket' => [
                'driver' => 'local',
                'root' => $this->tempPath.'/sample_images_bucket',
                'throw' => false,
            ],
        ]);

        foreach (['fullsize', 'archive', 'sample_images', 'sample_images_bucket'] as $disk) {
            Storage::forgetDisk($disk);
        }

        // Set up configuration for testing with actual files. Do this before
        // creating models so their events never scan the operator's directories.
        Config::set('proofgen.fullsize_home_dir', $this->tempPath.'/fullsize');
        Config::set('proofgen.archive_home_dir', $this->tempPath.'/archive');
        Config::set('proofgen.rename_files', true);
        Config::set('proofgen.archive_enabled', true); // Enable archiving for tests
        Config::set('proofgen.watermark_proofs', false); // Disable watermarking for tests
        Config::set('proofgen.watermark_font', storage_path('watermark_fonts/Georgia.ttf'));
        Config::set('proofgen.watermark_background_opacity', 70);
        Config::set('proofgen.watermark_foreground_opacity', 0);

        // Configure thumbnail settings
        Config::set('proofgen.thumbnails', [
            'small' => [
                'suffix' => '_s',
                'width' => 400,
                'height' => 600,
                'quality' => 90,
                'font_size' => 8,
                'bg_size' => 16,
            ],
            'large' => [
                'suffix' => '_l',
                'width' => 1024,
                'height' => 1536,
                'quality' => 90,
                'font_size' => 20,
                'bg_size' => 40,
            ],
        ]);

        // Configure web image settings
        Config::set('proofgen.web_images', [
            'suffix' => '_web',
            'width' => 800,
            'height' => 1200,
            'quality' => 90,
            'font_size' => 20,
            'bg_size' => 40,
        ]);

        // Configure highres image settings
        Config::set('proofgen.highres_images', [
            'suffix' => '_highres',
            'width' => 3000,
            'height' => 3000,
            'quality' => 95,
            'font_size' => 20,
            'bg_size' => 40,
        ]);

        // Set a flag to skip file operations in model events during tests
        config(['testing.skip_file_operations' => true]);

        // Selective Bus fake: outbound upload/metadata jobs are recorded but never
        // executed (no rsync/SSH/SFTP, no ferraraphoto API). Import and derivative
        // generation jobs still run for real.
        Bus::fake(self::OUTBOUND_JOBS);

        // Create the Show and ShowClass records in the database before creating any
        // class directory, otherwise Show::created would auto-register the directory
        // as a ShowClass and collide with the explicit row below.
        Show::create([
            'id' => $this->show,
            'name' => $this->show,
        ]);

        ShowClass::create([
            'id' => $this->show.'_'.$this->class,
            'show_id' => $this->show,
            'name' => $this->class,
        ]);

        // Create the necessary directories using PathResolver
        Storage::disk('fullsize')->makeDirectory('');
        Storage::disk('archive')->makeDirectory('');
        Storage::disk('fullsize')->makeDirectory($this->pathResolver->getFullsizePath($this->show, $this->class));
        Storage::disk('fullsize')->makeDirectory($this->pathResolver->getOriginalsPath($this->show, $this->class));
        Storage::disk('fullsize')->makeDirectory($this->pathResolver->getProofsPath($this->show, $this->class));
        Storage::disk('fullsize')->makeDirectory($this->pathResolver->getWebImagesPath($this->show, $this->class));
        Storage::disk('fullsize')->makeDirectory($this->pathResolver->getHighresImagesPath($this->show, $this->class));

        // Mock Redis for proof numbers using the facade (no alias mock — alias mocks
        // pollute global class state and break sibling tests).
        $redisClient = \Mockery::mock();
        $redisClient->shouldReceive('exists')->andReturn(false);
        $redisClient->shouldReceive('rpush')->andReturn(true);
        $redisClient->shouldReceive('lpop')->andReturnUsing(function () {
            static $proofNum = 1;

            return 'TEST'.str_pad($proofNum++, 3, '0', STR_PAD_LEFT);
        });
        $redisClient->shouldReceive('llen')->andReturn(0);

        Redis::shouldReceive('client')->andReturn($redisClient);

        // Create service instances
        $this->photoService = new PhotoService($this->pathResolver);

        // Seed deterministic synthetic originals in the temp sample directory so
        // tests never depend on storage/sample_images or the S3 sample bucket.
        $this->seedSyntheticSampleImages(3);
    }

    protected function tearDown(): void
    {
        // Clean up our temp directory
        if (isset($this->tempPath) && File::exists($this->tempPath)) {
            File::deleteDirectory($this->tempPath);
        }

        parent::tearDown();
    }

    /**
     * Write $count synthetic JPGs into the temp sample_images disk.
     *
     * @return string[] The relative paths written.
     */
    protected function seedSyntheticSampleImages(int $count = 1): array
    {
        $paths = [];

        for ($i = 1; $i <= $count; $i++) {
            $path = "{$this->show}/{$this->class}/IMG_".str_pad((string) $i, 5, '0', STR_PAD_LEFT).'.jpg';
            Storage::disk('sample_images')->put($path, $this->createValidTestImage());
            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * List the synthetic sample JPGs seeded for this test.
     *
     * @return string[]
     */
    protected function availableSampleImagePaths(): array
    {
        $files = Storage::disk('sample_images')->files("{$this->show}/{$this->class}");

        $images = array_values(array_filter($files, function (string $file) {
            return str_ends_with(strtolower($file), '.jpg');
        }));

        sort($images);

        return $images;
    }

    /**
     * Create a valid test image that meets our size requirements using GD
     *
     * @return string The binary content of the test image
     */
    protected function createValidTestImage(): string
    {
        // Create a 1200x800 image (larger than our minimum size requirement)
        $image = imagecreatetruecolor(1200, 800);

        // Set background to a light gray
        $bgColor = imagecolorallocate($image, 240, 240, 240);
        imagefill($image, 0, 0, $bgColor);

        // Draw some random shapes to make it more like a real image
        for ($i = 0; $i < 20; $i++) {
            $color = imagecolorallocate(
                $image,
                rand(0, 255),
                rand(0, 255),
                rand(0, 255)
            );

            // Random rectangles
            imagefilledrectangle(
                $image,
                rand(0, 1100),
                rand(0, 700),
                rand(100, 1200),
                rand(100, 800),
                $color
            );
        }

        // Add some text
        $textColor = imagecolorallocate($image, 0, 0, 0);
        imagestring($image, 5, 600, 400, 'Test Image for Proofgen', $textColor);

        // Get the binary data
        ob_start();
        imagejpeg($image, null, 90); // 90% quality - creates a larger file
        $imageData = ob_get_clean();

        // Free up memory
        imagedestroy($image);

        return $imageData;
    }

    /**
     * Test processing a single real image from sample directory using PhotoService
     */
    public function test_process_single_sample_image()
    {
        $sampleImagePath = $this->availableSampleImagePaths()[0];
        $sampleImage = Storage::disk('sample_images')->get($sampleImagePath);
        $testImageFilename = basename($sampleImagePath);
        $this->assertNotEmpty($sampleImage, 'No sample image found to test with');

        // Get the file size of the file
        $fileSize = strlen($sampleImage);
        $this->assertIsInt($fileSize, 'File size is not an integer');
        // Originally this assertion checked for a file > 1MB, but for testing purposes
        // we'll accept a smaller file as our synthetic test image is sufficient for testing the workflow
        // Check that the image is at least 30KB
        $this->assertGreaterThan(30 * 1024, $fileSize, 'Sample image is too small to test with');

        // Copy a sample image to our test fullsize disk
        $imagePath = "{$this->show}/{$this->class}/{$testImageFilename}";
        Storage::disk('fullsize')->put($imagePath, $sampleImage);

        // Get filesize of the file we just moved
        $fileSize = Storage::disk('fullsize')->size($imagePath);
        $this->assertIsInt($fileSize, 'File size is not an integer');
        // Use a more reasonable size threshold for testing (30KB instead of 1MB)
        $this->assertGreaterThan(30 * 1024, $fileSize, 'Sample image (after moving it) is too small to test with');

        // Define proof number for the test
        $proofNumber = 'TEST001';

        // Use PhotoService to process the image (disable job dispatching)
        $result = $this->photoService->processPhoto($imagePath, $proofNumber, false, false);

        // Extract paths from result
        $photo = $result['photo'];
        $fullsizeImagePath = $photo->relative_path;
        $proofDestPath = $result['proofDestPath'];
        $webImagesPath = $result['webImagesPath'];

        // Check that files are in the right places
        $this->assertTrue(
            Storage::disk('fullsize')->exists($fullsizeImagePath),
            'Processed image not found in expected location'
        );

        // The archive path follows the pattern: show/class/filename (without 'originals')
        $filename = basename($fullsizeImagePath);
        $archivePath = "{$this->show}/{$this->class}/{$filename}";
        $this->assertTrue(
            Storage::disk('archive')->exists($archivePath),
            'Archive copy not created in expected location'
        );

        // Verify the file exists where we expect it
        $this->assertTrue(
            Storage::disk('fullsize')->exists($fullsizeImagePath),
            "File not found at relative path in fullsize disk: {$fullsizeImagePath}"
        );

        // Use PhotoService for creating thumbnails and web images
        $this->photoService->generateThumbnails($photo->id, $proofDestPath, false);
        $this->photoService->generateWebImage($photo->id, $webImagesPath);

        // Get the suffixes for verification
        $smallSuffix = config('proofgen.thumbnails.small.suffix');
        $largeSuffix = config('proofgen.thumbnails.large.suffix');
        $webSuffix = config('proofgen.web_images.suffix');

        // Get the base filename without path
        $filename = basename($fullsizeImagePath, '.jpg');

        // Verify the thumbnails and web image were created
        $this->assertTrue(
            Storage::disk('fullsize')->exists("{$proofDestPath}/{$filename}{$smallSuffix}.jpg"),
            'Small thumbnail not created'
        );
        $this->assertTrue(
            Storage::disk('fullsize')->exists("{$proofDestPath}/{$filename}{$largeSuffix}.jpg"),
            'Large thumbnail not created'
        );
        $this->assertTrue(
            Storage::disk('fullsize')->exists("{$webImagesPath}/{$filename}{$webSuffix}.jpg"),
            'Web image not created'
        );
    }

    /**
     * Test processing multiple real images in bulk from sample directory
     */
    public function test_process_multiple_sample_images()
    {
        $sampleImages = $this->availableSampleImagePaths();
        $this->assertGreaterThanOrEqual(3, count($sampleImages), 'Not enough synthetic sample images to test with');
        $sampleImages = array_slice($sampleImages, 0, 3);
        $this->assertNotEmpty($sampleImages, 'No sample images found to test with');

        $fullsize_path = $this->pathResolver->getFullsizePath($this->show, $this->class);
        $originals_path = $this->pathResolver->getOriginalsPath($this->show, $this->class);
        $proofs_path = $this->pathResolver->getProofsPath($this->show, $this->class);
        $webImages_path = $this->pathResolver->getWebImagesPath($this->show, $this->class);

        // Copy sample images to our test fullsize disk
        foreach ($sampleImages as $index => $sampleImage) {
            $imageContent = Storage::disk('sample_images')->get($sampleImage);
            $filename = basename($sampleImage, '.jpg').'.jpg';
            Storage::disk('fullsize')->put("/{$this->show}/{$this->class}/{$filename}", $imageContent);
        }

        // Get all the files to process
        $testFiles = Storage::disk('fullsize')->files($fullsize_path);

        // Process each image using PhotoService
        $processedImages = [];
        foreach ($testFiles as $index => $file) {
            $proofNumber = 'TEST'.str_pad($index + 1, 3, '0', STR_PAD_LEFT);

            // Use PhotoService to process this image (no job dispatching)
            $result = $this->photoService->processPhoto($file, $proofNumber, false);
            $processedImages[] = $result;

            // Generate thumbnails and web images for each processed image
            $this->photoService->generateThumbnails(
                $result['photo']->id,
                $result['proofDestPath'],
                false
            );
            $this->photoService->generateWebImage(
                $result['photo']->id,
                $result['webImagesPath']
            );
        }

        // Verify that all the images were processed
        // Check originals directory
        $originals = Storage::disk('fullsize')->files($originals_path);
        $this->assertCount(count($sampleImages), $originals, 'Not all images were processed to the originals directory');

        // Check archive copies
        $archives = Storage::disk('archive')->files($fullsize_path);
        $this->assertCount(count($sampleImages), $archives, 'Not all images were archived');

        // Check thumbnails and web images
        $thumbs = Storage::disk('fullsize')->files($proofs_path);
        $webImages = Storage::disk('fullsize')->files($webImages_path);

        $this->assertCount(count($sampleImages) * 2, $thumbs, 'Not all thumbnails were created (should be 2 per image)');
        $this->assertCount(count($sampleImages), $webImages, 'Not all web images were created');
    }

    /**
     * Test with real job dispatching but using PhotoService inside the jobs
     */
    public function test_job_integration_with_photo_service()
    {
        Queue::fake();

        $sampleImage = $this->availableSampleImagePaths()[0];
        $imageContent = Storage::disk('sample_images')->get($sampleImage);
        $this->assertNotEmpty($imageContent, 'No sample image found to test with');
        $sampleFilename = basename($sampleImage);
        $imagePath = $this->pathResolver->getFullsizePath($this->show, $this->class)."/{$sampleFilename}";
        Storage::disk('fullsize')->put($imagePath, $imageContent);

        // Dispatch the job
        ImportPhoto::dispatch($imagePath, 'TEST001')->onQueue('imports');

        // Verify the job was dispatched
        Queue::assertPushedOn('imports', ImportPhoto::class);

        // Now instead of actually running the job (which Queue::fake prevents),
        // we'll execute the same PhotoService calls the job would make directly

        // This simulates ImportPhoto job execution
        $result = $this->photoService->processPhoto($imagePath, 'TEST001', false, false);
        $photo = $result['photo'];
        $fullsizeImagePath = $photo->relative_path;
        $proofDestPath = $result['proofDestPath'];
        $webImagesPath = $result['webImagesPath'];

        // Verify the image has been processed
        $this->assertTrue(
            Storage::disk('fullsize')->exists($fullsizeImagePath),
            'Processed image not found in expected location'
        );

        // Check archive copy
        // The archive path follows the pattern: show/class/filename (without 'originals')
        $filename = basename($fullsizeImagePath);
        $archivePath = "{$this->show}/{$this->class}/{$filename}";
        $this->assertTrue(
            Storage::disk('archive')->exists($archivePath),
            'Archive copy not created in expected location'
        );

        // Check that the original is gone
        $this->assertFalse(
            Storage::disk('fullsize')->exists($imagePath),
            'Original image was not removed'
        );

        // In a real workflow, thumbnail and web image jobs would be dispatched
        // Let's confirm they're dispatched normally
        GenerateThumbnails::dispatch($fullsizeImagePath, $proofDestPath)->onQueue('thumbnails');
        GenerateWebImage::dispatch($fullsizeImagePath, $webImagesPath)->onQueue('thumbnails');

        // Verify the jobs were dispatched
        Queue::assertPushedOn('thumbnails', GenerateThumbnails::class);
        Queue::assertPushedOn('thumbnails', GenerateWebImage::class);

        // Now simulate the execution of these jobs using the PhotoService
        $this->photoService->generateThumbnails($photo->id, $proofDestPath, false);
        $this->photoService->generateWebImage($photo->id, $webImagesPath);

        // Get the suffixes for verification
        $smallSuffix = config('proofgen.thumbnails.small.suffix');
        $largeSuffix = config('proofgen.thumbnails.large.suffix');
        $webSuffix = config('proofgen.web_images.suffix');

        // Get the base filename without path
        $filename = basename($fullsizeImagePath, '.jpg');

        // Verify the thumbnails and web image were created
        $this->assertTrue(
            Storage::disk('fullsize')->exists("{$proofDestPath}/{$filename}{$smallSuffix}.jpg"),
            'Small thumbnail not created'
        );
        $this->assertTrue(
            Storage::disk('fullsize')->exists("{$proofDestPath}/{$filename}{$largeSuffix}.jpg"),
            'Large thumbnail not created'
        );
        $this->assertTrue(
            Storage::disk('fullsize')->exists("{$webImagesPath}/{$filename}{$webSuffix}.jpg"),
            'Web image not created'
        );
    }

    /**
     * Test the actual watermarking and image manipulation functionality
     * This test uses real image processing to ensure watermark files and fonts
     * are correctly configured and working
     */
    public function test_actual_image_processing_with_watermarking()
    {
        // Enable watermarking for this test
        Config::set('proofgen.watermark_proofs', true);

        // Find a sample image to use
        $sampleImage = $this->availableSampleImagePaths()[0];
        $imageContent = Storage::disk('sample_images')->get($sampleImage);
        $this->assertNotEmpty($imageContent, 'No sample image found to test with');
        $imagePath = $this->pathResolver->getFullsizePath($this->show, $this->class).'/test_watermark.jpg';
        Storage::disk('fullsize')->put($imagePath, $imageContent);

        // Define proof number for the test
        $proofNumber = 'TEST001';

        // Use PhotoService to process the image (disable job dispatching)
        $result = $this->photoService->processPhoto($imagePath, $proofNumber, false, false);

        // Extract paths from result
        $photo = $result['photo'];
        $fullsizeImagePath = $photo->relative_path;
        $proofDestPath = $result['proofDestPath'];
        $webImagesPath = $result['webImagesPath'];

        // Now generate thumbnails with actual watermarking
        $this->photoService->generateThumbnails($photo->id, $proofDestPath, false);
        $this->photoService->generateWebImage($photo->id, $webImagesPath);

        // Get the suffixes for verification
        $smallSuffix = config('proofgen.thumbnails.small.suffix');
        $largeSuffix = config('proofgen.thumbnails.large.suffix');
        $webSuffix = config('proofgen.web_images.suffix');

        // Get the base filename without path
        $filename = basename($fullsizeImagePath, '.jpg');

        // Verify the thumbnails and web image exist
        $smallThumbPath = "{$proofDestPath}/{$filename}{$smallSuffix}.jpg";
        $largeThumbPath = "{$proofDestPath}/{$filename}{$largeSuffix}.jpg";
        $webImagePath = "{$webImagesPath}/{$filename}{$webSuffix}.jpg";

        $this->assertTrue(
            Storage::disk('fullsize')->exists($smallThumbPath),
            'Small thumbnail not created'
        );
        $this->assertTrue(
            Storage::disk('fullsize')->exists($largeThumbPath),
            'Large thumbnail not created'
        );
        $this->assertTrue(
            Storage::disk('fullsize')->exists($webImagePath),
            'Web image not created'
        );

        // Get the file sizes to confirm they're valid images
        $smallThumbSize = Storage::disk('fullsize')->size($smallThumbPath);
        $largeThumbSize = Storage::disk('fullsize')->size($largeThumbPath);
        $webImageSize = Storage::disk('fullsize')->size($webImagePath);

        // Verify the sizes are reasonable for proper images
        $this->assertGreaterThan(1024, $smallThumbSize, 'Small thumbnail is too small, may not have watermark');
        $this->assertGreaterThan(1024, $largeThumbSize, 'Large thumbnail is too small, may not have watermark');
        $this->assertGreaterThan(1024, $webImageSize, 'Web image is too small, may not be properly created');

        // Verify the web image is larger than the small thumbnail
        // This ensures different sizes were created
        $this->assertGreaterThan($smallThumbSize, $largeThumbSize, 'Large thumbnail should be bigger than small thumbnail');
    }
}
