<?php

namespace Tests\Feature;

use App\Services\CoreImageEnhancementService;
use App\Services\ImageEnhancementService;
use Tests\TestCase;

class ImageEnhancementPerformanceTest extends TestCase
{
    /**
     * Test enhancement performance comparison
     */
    public function test_enhancement_performance_comparison(): void
    {
        // Increase memory limit for image processing
        ini_set('memory_limit', '512M');

        // Skip if no sample images
        if (! file_exists(storage_path('sample_images'))) {
            $this->markTestSkipped('No sample images available for testing');
        }

        // Find a sample image
        $sampleImage = null;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(storage_path('sample_images'))
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), ['jpg', 'jpeg', 'png'])) {
                $sampleImage = $file->getPathname();
                break;
            }
        }

        if (! $sampleImage) {
            $this->markTestSkipped('No sample image found');
        }

        // Test standard enhancement service
        $standardService = new ImageEnhancementService;

        $standardStart = microtime(true);
        $standardService->enhance($sampleImage, 'percentile_clipping', [
            'percentile_low' => 0.1,
            'percentile_high' => 99.9,
        ]);
        $standardTime = microtime(true) - $standardStart;

        $results = [];
        $results['Standard'] = $standardTime;

        // Test Core Image enhancement service if available
        if (PHP_OS_FAMILY === 'Darwin') {
            $coreImageService = new CoreImageEnhancementService;

            if ($coreImageService->isCoreImageAvailable()) {
                $coreImageStart = microtime(true);
                $coreImageService->enhance($sampleImage, 'percentile_clipping', [
                    'percentile_low' => 0.1,
                    'percentile_high' => 99.9,
                ]);
                $coreImageTime = microtime(true) - $coreImageStart;
                $results['Core Image'] = $coreImageTime;
            }
        }

        // Output performance comparison
        $this->addToAssertionCount(1);

        echo "\n\nPerformance Comparison:\n";
        echo "------------------------\n";
        foreach ($results as $service => $time) {
            echo sprintf('%-15s: %6.2fms', $service, $time * 1000);
            if ($service !== 'Standard' && isset($results['Standard'])) {
                $improvement = $results['Standard'] / $time;
                echo sprintf(' (%4.1fx faster)', $improvement);
            }
            echo "\n";
        }
        echo "\n";

        // Assert that alternative services are faster than standard
        foreach ($results as $service => $time) {
            if ($service !== 'Standard') {
                $this->assertLessThan($results['Standard'], $time,
                    "{$service} should be faster than Standard service");
            }
        }

        // If no alternative services available, still pass
        if (count($results) === 1) {
            $this->assertTrue(true);
        }
    }
}
