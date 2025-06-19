<?php

namespace Tests\Unit\Services;

use App\Exceptions\SampleImagesNotFoundException;
use App\Services\SampleImagesService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SampleImagesServiceTest extends TestCase
{
    protected SampleImagesService $sampleImagesService;

    protected function setUp(): void
    {
        parent::setUp();

        // Create the service
        $this->sampleImagesService = new SampleImagesService;

        // Mock the sample_images disk
        Storage::fake('sample_images');

        // Mock the sample_images_bucket disk
        Storage::fake('sample_images_bucket');

        // Set up test config
        config([
            'proofgen.auto_download_sample_images' => true,
        ]);
    }

    public function test_has_sample_images_returns_false_when_empty()
    {
        // Empty disk, should return false
        $this->assertFalse($this->sampleImagesService->hasSampleImages());

        // Directory exists but has no files, should still return false
        Storage::disk('sample_images')->makeDirectory('show1/class1');
        $this->assertFalse($this->sampleImagesService->hasSampleImages());
    }

    public function test_has_sample_images_returns_true_when_images_exist()
    {
        // Create test file
        Storage::disk('sample_images')->put('show1/class1/test.jpg', 'test content');

        $this->assertTrue($this->sampleImagesService->hasSampleImages());
    }

    public function test_ensure_sample_images_available_throws_exception_when_not_found_and_auto_download_disabled()
    {
        // Disable auto-download
        config(['proofgen.auto_download_sample_images' => false]);

        $this->expectException(SampleImagesNotFoundException::class);
        $this->sampleImagesService->ensureSampleImagesAvailable();
    }

    public function test_download_sample_images_copies_files_from_s3_bucket()
    {
        // Setup some test files in the S3 bucket
        Storage::disk('sample_images_bucket')->put('show1/class1/test1.jpg', 'test content 1');
        Storage::disk('sample_images_bucket')->put('show1/class1/test2.jpg', 'test content 2');
        Storage::disk('sample_images_bucket')->put('show2/class2/test3.jpg', 'test content 3');

        // Download the sample images
        $result = $this->sampleImagesService->downloadSampleImages();

        // Verify the result
        $this->assertTrue($result);

        // Verify the files were copied to the local disk
        $this->assertTrue(Storage::disk('sample_images')->exists('show1/class1/test1.jpg'));
        $this->assertTrue(Storage::disk('sample_images')->exists('show1/class1/test2.jpg'));
        $this->assertTrue(Storage::disk('sample_images')->exists('show2/class2/test3.jpg'));

        // Verify the content was copied correctly
        $this->assertEquals('test content 1', Storage::disk('sample_images')->get('show1/class1/test1.jpg'));
        $this->assertEquals('test content 2', Storage::disk('sample_images')->get('show1/class1/test2.jpg'));
        $this->assertEquals('test content 3', Storage::disk('sample_images')->get('show2/class2/test3.jpg'));
    }

    public function test_ensure_sample_images_available_downloads_when_needed()
    {
        // Setup some test files in the S3 bucket
        Storage::disk('sample_images_bucket')->put('show1/class1/test1.jpg', 'test content 1');

        // Ensure sample images available
        $this->sampleImagesService->ensureSampleImagesAvailable();

        // Verify the files were copied to the local disk
        $this->assertTrue(Storage::disk('sample_images')->exists('show1/class1/test1.jpg'));
    }

    public function test_upload_sample_images()
    {
        // Create some test files in the sample_images disk
        Storage::disk('sample_images')->put('upload_test/image1.jpg', 'upload test content 1');
        Storage::disk('sample_images')->put('upload_test/image2.jpg', 'upload test content 2');

        // Upload the sample images
        $result = $this->sampleImagesService->uploadSampleImages();

        // Verify the result
        $this->assertEquals(2, $result['total']);
        $this->assertEquals(2, $result['uploaded']);
        $this->assertEquals(0, $result['skipped']);
        $this->assertEquals(0, $result['failed']);

        // Verify the files were uploaded to the S3 bucket
        $this->assertTrue(Storage::disk('sample_images_bucket')->exists('upload_test/image1.jpg'));
        $this->assertTrue(Storage::disk('sample_images_bucket')->exists('upload_test/image2.jpg'));

        // Verify the content was uploaded correctly
        $this->assertEquals('upload test content 1', Storage::disk('sample_images_bucket')->get('upload_test/image1.jpg'));
        $this->assertEquals('upload test content 2', Storage::disk('sample_images_bucket')->get('upload_test/image2.jpg'));
    }

    public function test_upload_sample_images_with_custom_path()
    {
        // Create a temp directory with test files
        $tempDir = sys_get_temp_dir().'/sample_images_test_'.uniqid();
        mkdir($tempDir, 0755, true);
        mkdir($tempDir.'/custom_path', 0755, true);

        // Create test files
        file_put_contents($tempDir.'/custom_path/test1.jpg', 'custom path test 1');
        file_put_contents($tempDir.'/custom_path/test2.jpg', 'custom path test 2');

        try {
            // Upload from the custom path
            $result = $this->sampleImagesService->uploadSampleImages($tempDir);

            // Verify the result
            $this->assertEquals(2, $result['total']);
            $this->assertEquals(2, $result['uploaded']);

            // Verify the files were uploaded to the S3 bucket with correct paths
            $this->assertTrue(Storage::disk('sample_images_bucket')->exists('custom_path/test1.jpg'));
            $this->assertTrue(Storage::disk('sample_images_bucket')->exists('custom_path/test2.jpg'));

            // Verify the content was uploaded correctly
            $this->assertEquals('custom path test 1', Storage::disk('sample_images_bucket')->get('custom_path/test1.jpg'));
            $this->assertEquals('custom path test 2', Storage::disk('sample_images_bucket')->get('custom_path/test2.jpg'));
        } finally {
            // Clean up
            $this->deleteDirectory($tempDir);
        }
    }

    public function test_upload_with_no_overwrite_option()
    {
        // Create some test files in the sample_images disk
        Storage::disk('sample_images')->put('no_overwrite_test/image1.jpg', 'original content');

        // Upload the file first time
        $this->sampleImagesService->uploadSampleImages();

        // Verify it was uploaded
        $this->assertTrue(Storage::disk('sample_images_bucket')->exists('no_overwrite_test/image1.jpg'));
        $this->assertEquals('original content', Storage::disk('sample_images_bucket')->get('no_overwrite_test/image1.jpg'));

        // Change the local file
        Storage::disk('sample_images')->put('no_overwrite_test/image1.jpg', 'changed content');

        // Upload again with no_overwrite = true
        $result = $this->sampleImagesService->uploadSampleImages(null, false);

        // Should have skipped the file
        $this->assertEquals(1, $result['total']);
        $this->assertEquals(0, $result['uploaded']);
        $this->assertEquals(1, $result['skipped']);

        // Content should remain original
        $this->assertEquals('original content', Storage::disk('sample_images_bucket')->get('no_overwrite_test/image1.jpg'));

        // Now upload with overwrite = true
        $result = $this->sampleImagesService->uploadSampleImages(null, true);

        // Should have uploaded the file
        $this->assertEquals(1, $result['total']);
        $this->assertEquals(1, $result['uploaded']);
        $this->assertEquals(0, $result['skipped']);

        // Content should be updated
        $this->assertEquals('changed content', Storage::disk('sample_images_bucket')->get('no_overwrite_test/image1.jpg'));
    }

    /**
     * Helper to recursively delete a directory
     */
    protected function deleteDirectory(string $dir)
    {
        if (! is_dir($dir)) {
            return;
        }

        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object == '.' || $object == '..') {
                continue;
            }

            $path = $dir.'/'.$object;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
