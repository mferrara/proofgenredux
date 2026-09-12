<?php

namespace Tests\Unit\Services;

use App\Services\ImageEnhancementService;
use Illuminate\Support\Facades\File;
use Intervention\Image\Image;
use RuntimeException;
use Tests\TestCase;

class ImageEnhancementServiceTest extends TestCase
{
    private ImageEnhancementService $service;

    private string $testImagePath;

    /** @var array<int, string> */
    private array $exportedImages = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ImageEnhancementService;
        $this->testImagePath = storage_path('app/test-enhancement-gradient.jpg');
        // Deliberately low-contrast (80-180) so auto-levels/percentile stretch
        // has a measurable effect to assert against.
        $this->createGradientImage($this->testImagePath, 80, 180);
    }

    protected function tearDown(): void
    {
        if (File::exists($this->testImagePath)) {
            File::delete($this->testImagePath);
        }

        foreach ($this->exportedImages as $path) {
            if (File::exists($path)) {
                File::delete($path);
            }
        }

        parent::tearDown();
    }

    public function test_positive_highlight_brightening_reports_the_supported_range(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Positive highlight brightening is not supported');
        $this->service->enhance($this->testImagePath, 'advanced_tone_mapping', ['tone_mapping_highlight_amount' => 25]);
    }

    /** @test */
    public function it_applies_basic_brightness_and_contrast_without_a_default_stretch()
    {
        $before = $this->luminanceStats($this->testImagePath);

        $result = $this->service->enhance($this->testImagePath, 'basic_auto_levels');
        $after = $this->luminanceStats($this->export($result));

        // Default black/white points (0/100) match the daemon, which skips the
        // levels pass; only the contrast boost (range 100 < threshold 200) and
        // brightness apply, so the range should grow but not hit full scale.
        $this->assertGreaterThan($before['range'], $after['range']);
        $this->assertLessThan(200, $after['range']);
        $this->assertEquals(100, $result->width());
        $this->assertEquals(100, $result->height());
    }

    /** @test */
    public function it_applies_saved_config_parameters_to_adjustable_auto_levels()
    {
        config([
            'proofgen.auto_levels_target_brightness' => 128.0,
            'proofgen.auto_levels_black_point' => 0.0,
            'proofgen.auto_levels_white_point' => 100.0,
        ]);
        $baseline = $this->luminanceStats($this->export(
            $this->service->enhance($this->testImagePath, 'adjustable_auto_levels')
        ));

        config(['proofgen.auto_levels_target_brightness' => 200.0]);
        $brightened = $this->luminanceStats($this->export(
            $this->service->enhance($this->testImagePath, 'adjustable_auto_levels')
        ));

        $this->assertGreaterThan($baseline['mean'] + 20, $brightened['mean']);
    }

    /** @test */
    public function it_lets_explicit_parameters_override_saved_config()
    {
        config(['proofgen.auto_levels_target_brightness' => 200.0]);
        $fromConfig = $this->luminanceStats($this->export(
            $this->service->enhance($this->testImagePath, 'adjustable_auto_levels')
        ));

        $overridden = $this->luminanceStats($this->export(
            $this->service->enhance($this->testImagePath, 'adjustable_auto_levels', [
                'auto_levels_target_brightness' => 128.0,
            ])
        ));

        $this->assertLessThan($fromConfig['mean'], $overridden['mean']);
    }

    /** @test */
    public function it_does_not_apply_adjustable_config_to_legacy_basic_auto_levels()
    {
        $default = $this->luminanceStats($this->export(
            $this->service->enhance($this->testImagePath, 'basic_auto_levels')
        ));

        config([
            'proofgen.auto_levels_target_brightness' => 240.0,
            'proofgen.auto_levels_black_point' => 5.0,
            'proofgen.auto_levels_white_point' => 95.0,
        ]);

        $withConfig = $this->luminanceStats($this->export(
            $this->service->enhance($this->testImagePath, 'basic_auto_levels')
        ));

        $this->assertEqualsWithDelta($default['mean'], $withConfig['mean'], 1.5);
    }

    /** @test */
    public function it_honors_black_and_white_point_percentiles()
    {
        $path = storage_path('app/test-enhancement-outliers.jpg');
        $this->createGradientImage($path, 100, 200, withOutliers: true);

        try {
            $clippedDark = $this->countPixelsBelow($this->export(
                $this->service->enhance($path, 'adjustable_auto_levels', [
                    'auto_levels_black_point' => 2.0,
                    'auto_levels_white_point' => 98.0,
                    'auto_levels_contrast_threshold' => 0.0,
                    'auto_levels_contrast_boost' => 1.0,
                    'auto_levels_target_brightness' => 128.0,
                ])
            ), 50);

            $unclippedDark = $this->countPixelsBelow($this->export(
                $this->service->enhance($path, 'adjustable_auto_levels', [
                    'auto_levels_black_point' => 0.0,
                    'auto_levels_white_point' => 100.0,
                    'auto_levels_contrast_threshold' => 0.0,
                    'auto_levels_contrast_boost' => 1.0,
                    'auto_levels_target_brightness' => 128.0,
                ])
            ), 50);

            // Clipping the extremes at 2%/98% maps the bulk gradient (100-200)
            // onto the full range, so many bulk pixels fall below 50. At
            // 0%/100% only the single black guard pixel (and any JPEG ringing
            // around it) does.
            $this->assertGreaterThan(1000, $clippedDark);
            $this->assertGreaterThan($unclippedDark + 500, $clippedDark);
        } finally {
            if (File::exists($path)) {
                File::delete($path);
            }
        }
    }

    /** @test */
    public function it_applies_percentile_clipping()
    {
        $before = $this->luminanceStats($this->testImagePath);

        $result = $this->service->enhance($this->testImagePath, 'percentile_clipping', [
            'tone_mapping_percentile_low' => 0.1,
            'tone_mapping_percentile_high' => 99.9,
        ]);
        $after = $this->luminanceStats($this->export($result));

        $this->assertGreaterThan($before['range'], $after['range']);
    }

    /** @test */
    public function it_applies_advanced_tone_mapping_with_defaults()
    {
        config([
            'proofgen.tone_mapping_percentile_low' => 0.1,
            'proofgen.tone_mapping_percentile_high' => 99.9,
            'proofgen.tone_mapping_shadow_amount' => 0.0,
            'proofgen.tone_mapping_highlight_amount' => 0.0,
            'proofgen.tone_mapping_midtone_gamma' => 1.0,
        ]);

        $before = $this->luminanceStats($this->testImagePath);

        $result = $this->service->enhance($this->testImagePath, 'advanced_tone_mapping');
        $after = $this->luminanceStats($this->export($result));

        $this->assertGreaterThan($before['range'], $after['range']);
    }

    /** @test */
    public function it_rejects_unknown_methods_instead_of_silently_succeeding()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('smart_indoor');

        $this->service->enhance($this->testImagePath, 'smart_indoor');
    }

    /** @test */
    public function it_throws_when_advanced_tone_mapping_shadow_cannot_be_applied()
    {
        $this->expectException(RuntimeException::class);

        $this->service->enhance($this->testImagePath, 'advanced_tone_mapping', [
            'tone_mapping_shadow_amount' => 25.0,
        ]);
    }

    /** @test */
    public function it_throws_when_advanced_tone_mapping_highlight_cannot_be_applied()
    {
        $this->expectException(RuntimeException::class);

        $this->service->enhance($this->testImagePath, 'advanced_tone_mapping', [
            'tone_mapping_highlight_amount' => -25.0,
        ]);
    }

    /** @test */
    public function it_applies_midtone_gamma_in_the_same_direction_as_core_image()
    {
        $parameters = [
            'tone_mapping_percentile_low' => 0,
            'tone_mapping_percentile_high' => 100,
            'tone_mapping_shadow_amount' => 0,
            'tone_mapping_highlight_amount' => 0,
            'tone_mapping_midtone_gamma' => 1,
        ];
        $baseline = $this->luminanceStats($this->export(
            $this->service->enhance($this->testImagePath, 'advanced_tone_mapping', $parameters)
        ));
        $parameters['tone_mapping_midtone_gamma'] = 1.5;
        $adjusted = $this->luminanceStats($this->export(
            $this->service->enhance($this->testImagePath, 'advanced_tone_mapping', $parameters)
        ));
        $this->assertLessThan($baseline['mean'] - 10, $adjusted['mean']);
    }

    /** @test */
    public function it_reports_only_reachable_methods()
    {
        $methods = ImageEnhancementService::getAvailableMethods();

        $this->assertEqualsCanonicalizing([
            'basic_auto_levels',
            'adjustable_auto_levels',
            'percentile_clipping',
            'advanced_tone_mapping',
        ], array_keys($methods));

        $this->assertArrayNotHasKey('percentile_with_curve', $methods);
        $this->assertArrayNotHasKey('clahe', $methods);
        $this->assertArrayNotHasKey('smart_indoor', $methods);
    }

    /** @test */
    public function it_leaves_no_temporary_files_behind_on_success()
    {
        $before = $this->tempEnhancementFiles();

        config([
            'proofgen.auto_levels_target_brightness' => 128.0,
            'proofgen.auto_levels_black_point' => 0.0,
            'proofgen.auto_levels_white_point' => 100.0,
            'proofgen.tone_mapping_shadow_amount' => 0.0,
            'proofgen.tone_mapping_highlight_amount' => 0.0,
            'proofgen.tone_mapping_midtone_gamma' => 1.0,
        ]);

        $this->service->enhance($this->testImagePath, 'adjustable_auto_levels');
        $this->service->enhance($this->testImagePath, 'percentile_clipping');
        $this->service->enhance($this->testImagePath, 'advanced_tone_mapping');

        $this->assertSame($before, $this->tempEnhancementFiles());
    }

    /** @test */
    public function it_leaves_no_temporary_files_behind_when_decoding_fails()
    {
        $brokenPath = storage_path('app/test-enhancement-broken.jpg');
        File::put($brokenPath, 'this is not an image');

        try {
            $before = $this->tempEnhancementFiles();

            try {
                $this->service->enhance($brokenPath, 'adjustable_auto_levels');
                $this->fail('Expected a RuntimeException for an undecodable image.');
            } catch (RuntimeException $e) {
                // Expected.
            }

            $this->assertSame($before, $this->tempEnhancementFiles());
        } finally {
            File::delete($brokenPath);
        }
    }

    /**
     * @return array<int, string>
     */
    private function tempEnhancementFiles(): array
    {
        $files = glob(sys_get_temp_dir().'/enhance_*') ?: [];
        sort($files);

        return $files;
    }

    private function countPixelsBelow(string $path, int $threshold): int
    {
        $contents = file_get_contents($path);
        $image = imagecreatefromstring($contents);

        if ($image === false) {
            $this->fail("Could not decode exported image: {$path}");
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $count = 0;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $luminance = (int) round(0.299 * $r + 0.587 * $g + 0.114 * $b);

                if ($luminance < $threshold) {
                    $count++;
                }
            }
        }

        imagedestroy($image);

        return $count;
    }

    private function export(Image $image): string
    {
        $base = tempnam(sys_get_temp_dir(), 'enhance_export_');
        $path = $base.'.jpg';
        @unlink($base);

        $image->save($path, quality: 100);
        $this->exportedImages[] = $path;

        return $path;
    }

    /**
     * @return array{min: int, max: int, mean: float, range: int}
     */
    private function luminanceStats(string $path): array
    {
        $contents = file_get_contents($path);
        $image = imagecreatefromstring($contents);

        if ($image === false) {
            $this->fail("Could not decode exported image: {$path}");
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $min = 255;
        $max = 0;
        $sum = 0;
        $count = 0;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $luminance = (int) round(0.299 * $r + 0.587 * $g + 0.114 * $b);
                $min = min($min, $luminance);
                $max = max($max, $luminance);
                $sum += $luminance;
                $count++;
            }
        }

        imagedestroy($image);

        return [
            'min' => $min,
            'max' => $max,
            'mean' => $count > 0 ? $sum / $count : 0.0,
            'range' => $max - $min,
        ];
    }

    private function createGradientImage(string $path, int $low = 0, int $high = 255, bool $withOutliers = false): void
    {
        $width = 100;
        $height = 100;
        $image = imagecreatetruecolor($width, $height);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $t = ($x + $y) / (($width - 1) + ($height - 1));
                $value = (int) round($low + $t * ($high - $low));
                $color = imagecolorallocate($image, $value, $value, $value);
                imagesetpixel($image, $x, $y, $color);
            }
        }

        if ($withOutliers) {
            $black = imagecolorallocate($image, 0, 0, 0);
            $white = imagecolorallocate($image, 255, 255, 255);
            imagesetpixel($image, 0, 0, $black);
            imagesetpixel($image, $width - 1, $height - 1, $white);
        }

        imagejpeg($image, $path, 100);
        imagedestroy($image);
    }
}
