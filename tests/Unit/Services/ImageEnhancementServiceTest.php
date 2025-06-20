<?php

namespace Tests\Unit\Services;

use App\Services\ImageEnhancementService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ImageEnhancementServiceTest extends TestCase
{
    private ImageEnhancementService $service;
    private string $testImagePath;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->service = new ImageEnhancementService();
        
        // Create a test image
        $this->testImagePath = storage_path('app/test-image.jpg');
        $this->createTestImage();
    }

    protected function tearDown(): void
    {
        // Clean up test image
        if (File::exists($this->testImagePath)) {
            File::delete($this->testImagePath);
        }
        
        parent::tearDown();
    }

    /** @test */
    public function it_can_apply_basic_auto_levels()
    {
        $result = $this->service->enhance($this->testImagePath, 'basic_auto_levels');
        
        $this->assertNotNull($result);
        $this->assertEquals(100, $result->width());
        $this->assertEquals(100, $result->height());
    }

    /** @test */
    public function it_can_apply_percentile_clipping()
    {
        $result = $this->service->enhance($this->testImagePath, 'percentile_clipping', [
            'percentile_low' => 0.1,
            'percentile_high' => 99.9
        ]);
        
        $this->assertNotNull($result);
    }

    /** @test */
    public function it_can_apply_percentile_with_curve()
    {
        $result = $this->service->enhance($this->testImagePath, 'percentile_with_curve');
        
        $this->assertNotNull($result);
    }

    /** @test */
    public function it_can_apply_clahe()
    {
        $result = $this->service->enhance($this->testImagePath, 'clahe', [
            'clahe_clip_limit' => 2.0,
            'clahe_grid_size' => 8
        ]);
        
        $this->assertNotNull($result);
    }

    /** @test */
    public function it_can_apply_smart_indoor_enhancement()
    {
        $result = $this->service->enhance($this->testImagePath, 'smart_indoor');
        
        $this->assertNotNull($result);
    }

    /** @test */
    public function it_returns_original_image_for_unknown_method()
    {
        $result = $this->service->enhance($this->testImagePath, 'unknown_method');
        
        $this->assertNotNull($result);
    }

    /** @test */
    public function it_gets_available_methods()
    {
        $methods = ImageEnhancementService::getAvailableMethods();
        
        $this->assertIsArray($methods);
        $this->assertArrayHasKey('basic_auto_levels', $methods);
        $this->assertArrayHasKey('percentile_clipping', $methods);
        $this->assertArrayHasKey('percentile_with_curve', $methods);
        $this->assertArrayHasKey('clahe', $methods);
        $this->assertArrayHasKey('smart_indoor', $methods);
    }

    private function createTestImage(): void
    {
        // Create a simple test image
        $image = imagecreatetruecolor(100, 100);
        
        // Add some variation to test enhancement
        for ($x = 0; $x < 100; $x++) {
            for ($y = 0; $y < 100; $y++) {
                $gray = ($x + $y) % 256;
                $color = imagecolorallocate($image, $gray, $gray, $gray);
                imagesetpixel($image, $x, $y, $color);
            }
        }
        
        imagejpeg($image, $this->testImagePath, 90);
        imagedestroy($image);
    }
}