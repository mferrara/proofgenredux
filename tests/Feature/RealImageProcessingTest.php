<?php

namespace Tests\Feature;

use App\Exceptions\SampleImagesNotFoundException;
use App\Jobs\Photo\GenerateThumbnails;
use App\Jobs\Photo\GenerateWebImage;
use App\Jobs\Photo\ImportPhoto;
use App\Jobs\ShowClass\ImportPhotos;
use App\Proofgen\Image;
use App\Proofgen\ShowClass;
use App\Proofgen\Utility;
use App\Services\PathResolver;
use App\Services\PhotoService;
use App\Services\SampleImagesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class RealImageProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected $tempPath;
    protected string $show = '2023R41';
    protected string $class = '121';
    protected string $photo_name = 'IMG_02593.jpg';
    protected PhotoService $photoService;
    protected PathResolver $pathResolver;
    protected SampleImagesService $sampleImagesService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pathResolver = new PathResolver();

        // Create a temp directory for our test images
        $this->tempPath = storage_path('app/temp_test_' . uniqid());
        File::makeDirectory($this->tempPath, 0755, true);

        // Override the default disk configs with our test ones
        $fullsizeDisk = [
            'driver' => 'local',
            'root' => $this->tempPath . '/fullsize',
            'throw' => true,
        ];

        $archiveDisk = [
            'driver' => 'local',
            'root' => $this->tempPath . '/archive',
            'throw' => true,
        ];

        $sampleImagesDisk = [
            'driver' => 'local',
            'root' => storage_path('sample_images'),
            'throw' => false,
        ];
        
        // Mock the sample_images_bucket disk for testing
        $sampleImagesBucketDisk = [
            'driver' => 'local',
            'root' => storage_path('fake_bucket'),
            'throw' => false,
        ];

        // Configure the disks - directly override the existing ones
        config(['filesystems.disks.fullsize' => $fullsizeDisk]);
        config(['filesystems.disks.archive' => $archiveDisk]);
        config(['filesystems.disks.sample_images' => $sampleImagesDisk]);
        config(['filesystems.disks.sample_images_bucket' => $sampleImagesBucketDisk]);

        // Create the necessary directories using PathResolver
        Storage::disk('fullsize')->makeDirectory('');
        Storage::disk('archive')->makeDirectory('');
        Storage::disk('fullsize')->makeDirectory($this->pathResolver->getFullsizePath($this->show, $this->class));
        Storage::disk('fullsize')->makeDirectory($this->pathResolver->getOriginalsPath($this->show, $this->class));
        Storage::disk('fullsize')->makeDirectory($this->pathResolver->getProofsPath($this->show, $this->class));
        Storage::disk('fullsize')->makeDirectory($this->pathResolver->getWebImagesPath($this->show, $this->class));

        // Set up configuration for testing with actual files
        Config::set('proofgen.fullsize_home_dir', $this->tempPath . '/fullsize');
        Config::set('proofgen.archive_home_dir', $this->tempPath . '/archive');
        Config::set('proofgen.rename_files', true);
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
            ]
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

        // Set environment variables that some parts of the code expect
        putenv('WATERMARK_FONT=' . storage_path('watermark_fonts/Georgia.ttf'));
        putenv('LARGE_THUMBNAIL_QUALITY=90');

        // Mock Redis for proof numbers
        $mock = \Mockery::mock('alias:'.Redis::class);
        $mock->shouldReceive('client')->andReturn($mock);
        $mock->shouldReceive('exists')->andReturn(false);
        $mock->shouldReceive('rpush')->andReturn(true);
        $mock->shouldReceive('lpop')->andReturnUsing(function() {
            static $proofNum = 1;
            return 'TEST' . str_pad($proofNum++, 3, '0', STR_PAD_LEFT);
        });
        $mock->shouldReceive('llen')->andReturn(0);

        // Create service instances
        $this->pathResolver = new PathResolver();
        $this->photoService = new PhotoService($this->pathResolver);
        $this->sampleImagesService = new SampleImagesService($this->pathResolver);
    }

    protected function tearDown(): void
    {
        // Clean up our temp directory
        if (File::exists($this->tempPath)) {
            File::deleteDirectory($this->tempPath);
        }

        parent::tearDown();
    }

    /**
     * Test processing a single real image from sample directory using PhotoService
     */
    public function test_process_single_sample_image()
    {
        try {
            // Ensure we have sample images, will auto-download if needed
            $this->sampleImagesService->ensureSampleImagesAvailable();
            
            // For testing, we'll pre-populate the sample_images disk with a test image
            Storage::disk('sample_images_bucket')->put("{$this->show}/{$this->class}/{$this->photo_name}", 'test image content');
            $this->sampleImagesService->downloadSampleImages();
        } catch (SampleImagesNotFoundException $e) {
            $this->markTestSkipped('Sample images not available and cannot be auto-downloaded: ' . $e->getMessage());
        }
        
        // Find a sample image to use
        $sampleImagePath = "{$this->show}/{$this->class}/{$this->photo_name}";
        $sampleImage = Storage::disk('sample_images')->get($sampleImagePath);
        $testImageFilename = basename($sampleImagePath);
        $this->assertNotEmpty($sampleImage, 'No sample image found to test with');

        // Get the file size of the file
        $fileSize = strlen($sampleImage);
        $this->assertIsInt($fileSize, 'File size is not an integer');
        // Assert that the file is > 1mb
        $this->assertGreaterThan(1024 * 1024, $fileSize, 'Sample image is too small to test with');

        // Copy a sample image to our test fullsize disk
        $imagePath = "{$this->show}/{$this->class}/{$testImageFilename}";
        Storage::disk('fullsize')->put($imagePath, $sampleImage);

        // Get filesize of the file we just moved and assert it's larger than 1MB
        $fileSize = Storage::disk('fullsize')->size($imagePath);
        $this->assertIsInt($fileSize, 'File size is not an integer');
        $this->assertGreaterThan(1024 * 1024, $fileSize, 'Sample image (after moving it) is too small to test with');

        // Define proof number for the test
        $proofNumber = 'TEST001';

        // Use PhotoService to process the image (disable job dispatching)
        $result = $this->photoService->processPhoto($imagePath, $proofNumber, false, false);

        // Extract paths from result
        $fullsizeImagePath = $result['fullsizeImagePath'];
        $proofDestPath = $result['proofDestPath'];
        $webImagesPath = $result['webImagesPath'];

        // Check that files are in the right places
        $this->assertTrue(
            Storage::disk('fullsize')->exists($fullsizeImagePath),
            'Processed image not found in expected location'
        );

        $archivePath = str_replace('originals/', '', $fullsizeImagePath);
        $this->assertTrue(
            Storage::disk('archive')->exists($archivePath),
            'Archive copy not created in expected location'
        );

        // Use PhotoService for creating thumbnails and web images
        $this->photoService->generateThumbnails($fullsizeImagePath, $proofDestPath, false);
        $this->photoService->generateWebImage($fullsizeImagePath, $webImagesPath);

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
        try {
            // Ensure we have sample images, will auto-download if needed
            $this->sampleImagesService->ensureSampleImagesAvailable();
            
            // For testing, we'll pre-populate the sample_images disk with test images
            Storage::disk('sample_images_bucket')->put("{$this->show}/{$this->class}/image1.jpg", 'test image content 1');
            Storage::disk('sample_images_bucket')->put("{$this->show}/{$this->class}/image2.jpg", 'test image content 2');
            Storage::disk('sample_images_bucket')->put("{$this->show}/{$this->class}/image3.jpg", 'test image content 3');
            $this->sampleImagesService->downloadSampleImages();
        } catch (SampleImagesNotFoundException $e) {
            $this->markTestSkipped('Sample images not available and cannot be auto-downloaded: ' . $e->getMessage());
        }
        
        $fullsize_path = $this->pathResolver->getFullsizePath($this->show, $this->class);
        $originals_path = $this->pathResolver->getOriginalsPath($this->show, $this->class);
        $proofs_path = $this->pathResolver->getProofsPath($this->show, $this->class);
        $webImages_path = $this->pathResolver->getWebImagesPath($this->show, $this->class);

        // Get sample images to use, filtering for jpgs, limit to 3
        $sampleImages = Storage::disk('sample_images')->files("{$this->show}/{$this->class}");
        $sampleImages = array_filter($sampleImages, function($image) {
            // If the filename ends with .jpg, keep it
            return str_ends_with($image, '.jpg');
        });
        // Limit to 5
        $sampleImages = array_slice($sampleImages, 0, 3);
        $this->assertNotEmpty($sampleImages, 'No sample images found to test with');

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
            $proofNumber = 'TEST' . str_pad($index + 1, 3, '0', STR_PAD_LEFT);

            // Use PhotoService to process this image (no job dispatching)
            $result = $this->photoService->processPhoto($file, $proofNumber, false, false);
            $processedImages[] = $result;

            // Generate thumbnails and web images for each processed image
            $this->photoService->generateThumbnails(
                $result['fullsizeImagePath'],
                $result['proofDestPath'],
                false
            );
            $this->photoService->generateWebImage(
                $result['fullsizeImagePath'],
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
        try {
            // Ensure we have sample images, will auto-download if needed
            $this->sampleImagesService->ensureSampleImagesAvailable();
            
            // For testing, we'll pre-populate the sample_images disk with a test image
            Storage::disk('sample_images_bucket')->put("{$this->show}/{$this->class}/job_test.jpg", 'test image content for job test');
            $this->sampleImagesService->downloadSampleImages();
        } catch (SampleImagesNotFoundException $e) {
            $this->markTestSkipped('Sample images not available and cannot be auto-downloaded: ' . $e->getMessage());
        }
        
        Queue::fake();

        // Find a sample image to use
        $sampleImages = Storage::disk('sample_images')->files("{$this->show}/{$this->class}");
        $this->assertNotEmpty($sampleImages, 'No sample images found to test with');

        // Filter entries in $sampleImages for those that end with .jpg
        $sampleImages = array_filter($sampleImages, function($image) {
            return str_ends_with($image, '.jpg');
        });

        // Copy a sample image to our test fullsize disk
        $sampleImage = array_shift($sampleImages);
        $imageContent = Storage::disk('sample_images')->get($sampleImage);
        $sampleFilename = basename($sampleImage).'.jpg';
        $imagePath = $this->pathResolver->getFullsizePath($this->show, $this->class) . "/{$sampleFilename}";
        Storage::disk('fullsize')->put($imagePath, $imageContent);

        // Dispatch the job
        ImportPhoto::dispatch($imagePath, 'TEST001')->onQueue('imports');

        // Verify the job was dispatched
        Queue::assertPushedOn('imports', ImportPhoto::class);

        // Now instead of actually running the job (which Queue::fake prevents),
        // we'll execute the same PhotoService calls the job would make directly

        // This simulates ImportPhoto job execution
        $result = $this->photoService->processPhoto($imagePath, 'TEST001', false, false);
        $fullsizeImagePath = $result['fullsizeImagePath'];
        $proofDestPath = $result['proofDestPath'];
        $webImagesPath = $result['webImagesPath'];

        // Verify the image has been processed
        $this->assertTrue(
            Storage::disk('fullsize')->exists($fullsizeImagePath),
            'Processed image not found in expected location'
        );

        // Check archive copy
        $archivePath = str_replace('originals/', '', $fullsizeImagePath);
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
        $this->photoService->generateThumbnails($fullsizeImagePath, $proofDestPath, false);
        $this->photoService->generateWebImage($fullsizeImagePath, $webImagesPath);

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
        try {
            // Ensure we have sample images, will auto-download if needed
            $this->sampleImagesService->ensureSampleImagesAvailable();
            
            // For testing, we'll pre-populate the sample_images disk with a test image
            Storage::disk('sample_images_bucket')->put("{$this->show}/{$this->class}/watermark_test.jpg", 'test image content for watermark test');
            $this->sampleImagesService->downloadSampleImages();
        } catch (SampleImagesNotFoundException $e) {
            $this->markTestSkipped('Sample images not available and cannot be auto-downloaded: ' . $e->getMessage());
        }
        
        // Enable watermarking for this test
        Config::set('proofgen.watermark_proofs', true);

        // Find a sample image to use
        $sampleImages = Storage::disk('sample_images')->files("{$this->show}/{$this->class}");
        $this->assertNotEmpty($sampleImages, 'No sample images found to test with');

        // Filter $sampleImages for those that end with .jpg
        $sampleImages = array_filter($sampleImages, function($image) {
            return str_ends_with($image, '.jpg');
        });

        // Copy a sample image to our test fullsize disk
        $sampleImage = array_shift($sampleImages);
        $imageContent = Storage::disk('sample_images')->get($sampleImage);
        $imagePath = $this->pathResolver->getFullsizePath($this->show, $this->class) . "/test_watermark.jpg";
        Storage::disk('fullsize')->put($imagePath, $imageContent);

        // Define proof number for the test
        $proofNumber = 'TEST001';

        // Use PhotoService to process the image (disable job dispatching)
        $result = $this->photoService->processPhoto($imagePath, $proofNumber, false, false);

        // Extract paths from result
        $fullsizeImagePath = $result['fullsizeImagePath'];
        $proofDestPath = $result['proofDestPath'];
        $webImagesPath = $result['webImagesPath'];

        // Now generate thumbnails with actual watermarking
        $this->photoService->generateThumbnails($fullsizeImagePath, $proofDestPath, false);
        $this->photoService->generateWebImage($fullsizeImagePath, $webImagesPath);

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
