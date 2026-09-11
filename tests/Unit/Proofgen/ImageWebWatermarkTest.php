<?php

namespace Tests\Unit\Proofgen;

use App\Proofgen\Image;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Image::createWebImage watermark guard.
 *
 * The watermark asset lives in storage/watermarks (git-tracked). These tests
 * swap the application storage path to a throwaway directory so a missing or
 * corrupt watermark can be simulated without touching the real asset.
 */
class ImageWebWatermarkTest extends TestCase
{
    private string $realStoragePath;

    private string $workDir;

    private string $sourceDiskRoot;

    private string $sandboxStoragePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->realStoragePath = app()->storagePath();
        $this->workDir = $this->realStoragePath.'/app/web_watermark_test_'.uniqid();
        $this->sourceDiskRoot = $this->workDir.'/fullsize';
        $this->sandboxStoragePath = $this->workDir.'/storage';

        File::makeDirectory($this->sourceDiskRoot, 0755, true);
        File::makeDirectory($this->sandboxStoragePath.'/watermarks', 0755, true);

        config([
            'proofgen.fullsize_home_dir' => $this->sourceDiskRoot,
            'proofgen.image_enhancement_enabled' => false,
            'proofgen.web_images' => [
                'suffix' => '_web',
                'width' => 600,
                'height' => 400,
                'quality' => 80,
            ],
            'filesystems.disks.fullsize' => [
                'driver' => 'local',
                'root' => $this->sourceDiskRoot,
                'throw' => true,
            ],
        ]);

        Storage::forgetDisk('fullsize');
        app()->useStoragePath($this->sandboxStoragePath);

        $this->putSourceJpeg('SHOW/121/IMG_0001.jpg', 1200, 800);
    }

    protected function tearDown(): void
    {
        app()->useStoragePath($this->realStoragePath);

        if (File::exists($this->workDir)) {
            File::deleteDirectory($this->workDir);
        }

        parent::tearDown();
    }

    public function test_missing_watermark_fails_before_any_output_is_written(): void
    {
        $expectedOutput = $this->sourceDiskRoot.'/web_images/SHOW1/121/IMG_0001_web.jpg';

        try {
            Image::createWebImage('SHOW/121/IMG_0001.jpg', 'web_images/SHOW1/121');
            $this->fail('Expected a misleading-web-image guard for the missing watermark.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('watermark', $e->getMessage());
            $this->assertStringContainsString($this->sandboxStoragePath.'/watermarks/web-image-watermark-2.png', $e->getMessage());
            $this->assertStringContainsString($expectedOutput, $e->getMessage());
        }

        $this->assertFileDoesNotExist($expectedOutput, 'No output may be written without a validated watermark.');
    }

    public function test_corrupt_watermark_fails_before_any_output_is_written(): void
    {
        File::put($this->sandboxStoragePath.'/watermarks/web-image-watermark-2.png', 'this is not a png');

        $expectedOutput = $this->sourceDiskRoot.'/web_images/SHOW1/121/IMG_0001_web.jpg';

        try {
            Image::createWebImage('SHOW/121/IMG_0001.jpg', 'web_images/SHOW1/121');
            $this->fail('Expected a misleading-web-image guard for the undecodable watermark.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('could not be decoded', $e->getMessage());
        }

        $this->assertFileDoesNotExist($expectedOutput, 'No output may be written without a validated watermark.');
    }

    public function test_valid_watermark_writes_the_watermarked_web_image(): void
    {
        File::copy(
            $this->realStoragePath.'/watermarks/web-image-watermark-2.png',
            $this->sandboxStoragePath.'/watermarks/web-image-watermark-2.png'
        );

        $result = Image::createWebImage('SHOW/121/IMG_0001.jpg', 'web_images/SHOW1/121');

        $this->assertSame($this->sourceDiskRoot.'/web_images/SHOW1/121/IMG_0001_web.jpg', $result);
        $this->assertFileExists($result);

        $size = getimagesize($result);
        $this->assertSame(600, $size[0]);
        $this->assertSame(400, $size[1]);
    }

    private function putSourceJpeg(string $path, int $width, int $height): void
    {
        $gd = imagecreatetruecolor($width, $height);
        imagefill($gd, 0, 0, imagecolorallocate($gd, 90, 120, 150));

        ob_start();
        imagejpeg($gd, null, 90);
        $bytes = ob_get_clean();
        imagedestroy($gd);

        Storage::disk('fullsize')->put($path, $bytes);
    }
}
