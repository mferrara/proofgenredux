<?php

namespace Tests\Feature;

use App\Services\CoreImageDaemonService;
use App\Services\ImageEnhancementService;
use Illuminate\Support\Facades\File;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;
use RuntimeException;
use Tests\TestCase;

/**
 * Test-only CoreImageDaemonService double.
 *
 * The real constructor probes Swift compatibility and can spawn/attach to the
 * real daemon, so this override initializes only what enhance() needs and
 * replaces the socket call with a deterministic in-process response.
 */
class FakeCoreImageDaemonService extends CoreImageDaemonService
{
    /** @var array<int, array<string, mixed>> */
    public array $requests = [];

    /** success|error|garbage|throw */
    public string $behaviour = 'success';

    public function __construct()
    {
        // Deliberately does not call parent::__construct(): no Swift check, no
        // daemon startup, no process spawning.
        $this->manager = new ImageManager(GdDriver::class);
        $this->coreImageAvailable = true;
    }

    protected function sendRequestToDaemon(array $request): array
    {
        $this->requests[] = $request;

        if ($this->behaviour === 'throw') {
            throw new \Exception('synthetic daemon connection failure');
        }

        if ($this->behaviour === 'error') {
            return [
                'success' => false,
                'outputPath' => null,
                'error' => 'synthetic daemon failure',
                'processingTime' => 0.0,
            ];
        }

        if ($this->behaviour === 'garbage') {
            File::put($request['outputPath'], 'not an image');

            return [
                'success' => true,
                'outputPath' => $request['outputPath'],
                'error' => null,
                'processingTime' => 0.0,
            ];
        }

        $image = imagecreatetruecolor(20, 20);
        imagefilledrectangle($image, 0, 0, 19, 19, imagecolorallocate($image, 120, 120, 120));
        imagejpeg($image, $request['outputPath'], 90);
        imagedestroy($image);

        return [
            'success' => true,
            'outputPath' => $request['outputPath'],
            'error' => null,
            'processingTime' => 0.0,
        ];
    }
}

class EnhancementFallbackBehaviorTest extends TestCase
{
    private string $inputImagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inputImagePath = storage_path('app/test-enhancement-fallback-input.jpg');
        $this->createGradientImage($this->inputImagePath);
    }

    protected function tearDown(): void
    {
        if (File::exists($this->inputImagePath)) {
            File::delete($this->inputImagePath);
        }

        parent::tearDown();
    }

    /** @test */
    public function it_sends_saved_adjustable_auto_level_config_to_the_daemon()
    {
        config([
            'proofgen.auto_levels_target_brightness' => 137.0,
            'proofgen.auto_levels_contrast_threshold' => 180.0,
            'proofgen.auto_levels_contrast_boost' => 1.45,
            'proofgen.auto_levels_black_point' => 3.5,
            'proofgen.auto_levels_white_point' => 97.5,
        ]);

        $service = new FakeCoreImageDaemonService;
        $result = $service->enhance($this->inputImagePath, 'adjustable_auto_levels');

        $this->assertNotNull($result);
        $this->assertCount(1, $service->requests);

        $parameters = $service->requests[0]['parameters'];

        $this->assertSame(137.0, $parameters['auto_levels_target_brightness']);
        $this->assertSame(180.0, $parameters['auto_levels_contrast_threshold']);
        $this->assertSame(1.45, $parameters['auto_levels_contrast_boost']);
        $this->assertSame(3.5, $parameters['auto_levels_black_point']);
        $this->assertSame(97.5, $parameters['auto_levels_white_point']);
    }

    /** @test */
    public function it_sends_saved_advanced_tone_mapping_config_to_the_daemon()
    {
        config([
            'proofgen.tone_mapping_percentile_low' => 0.5,
            'proofgen.tone_mapping_percentile_high' => 99.5,
            'proofgen.tone_mapping_shadow_amount' => 20.0,
            'proofgen.tone_mapping_highlight_amount' => -15.0,
            'proofgen.tone_mapping_shadow_radius' => 40.0,
            'proofgen.tone_mapping_midtone_gamma' => 1.1,
        ]);

        $service = new FakeCoreImageDaemonService;
        $service->enhance($this->inputImagePath, 'advanced_tone_mapping');

        $parameters = $service->requests[0]['parameters'];

        $this->assertSame(0.5, $parameters['tone_mapping_percentile_low']);
        $this->assertSame(99.5, $parameters['tone_mapping_percentile_high']);
        $this->assertSame(20.0, $parameters['tone_mapping_shadow_amount']);
        $this->assertSame(-15.0, $parameters['tone_mapping_highlight_amount']);
        $this->assertSame(40.0, $parameters['tone_mapping_shadow_radius']);
        $this->assertSame(1.1, $parameters['tone_mapping_midtone_gamma']);
    }

    /** @test */
    public function it_lets_explicit_preview_parameters_override_saved_config()
    {
        config([
            'proofgen.auto_levels_target_brightness' => 200.0,
            'proofgen.auto_levels_black_point' => 4.0,
            'proofgen.auto_levels_white_point' => 96.0,
        ]);

        $service = new FakeCoreImageDaemonService;
        $service->enhance($this->inputImagePath, 'adjustable_auto_levels', [
            'auto_levels_target_brightness' => 150.0,
            'auto_levels_black_point' => 1.25,
        ]);

        $parameters = $service->requests[0]['parameters'];

        // Explicit preview values win...
        $this->assertSame(150.0, $parameters['auto_levels_target_brightness']);
        $this->assertSame(1.25, $parameters['auto_levels_black_point']);
        // ...while unspecified values still come from saved config.
        $this->assertSame(96.0, $parameters['auto_levels_white_point']);
    }

    /** @test */
    public function it_cleans_up_the_daemon_output_temp_file_on_success()
    {
        $service = new FakeCoreImageDaemonService;
        $service->enhance($this->inputImagePath, 'adjustable_auto_levels');

        $outputPath = $service->requests[0]['outputPath'];

        $this->assertFalse(file_exists($outputPath));
        $this->assertFalse(file_exists((string) preg_replace('/\.jpg$/', '', $outputPath)));
    }

    /** @test */
    public function it_cleans_up_and_falls_back_when_the_daemon_returns_an_error()
    {
        $service = new FakeCoreImageDaemonService;
        $service->behaviour = 'error';

        $result = $service->enhance($this->inputImagePath, 'adjustable_auto_levels');

        // A failed daemon response falls back to the GD service, which still
        // produces a usable (enhanced) image.
        $this->assertNotNull($result);

        $outputPath = $service->requests[0]['outputPath'];
        $this->assertFalse(file_exists($outputPath));
        $this->assertFalse(file_exists((string) preg_replace('/\.jpg$/', '', $outputPath)));
    }

    /** @test */
    public function it_cleans_up_and_falls_back_when_the_daemon_output_cannot_be_decoded()
    {
        $service = new FakeCoreImageDaemonService;
        $service->behaviour = 'garbage';

        $result = $service->enhance($this->inputImagePath, 'adjustable_auto_levels');

        $this->assertNotNull($result);

        $outputPath = $service->requests[0]['outputPath'];
        $this->assertFalse(file_exists($outputPath));
        $this->assertFalse(file_exists((string) preg_replace('/\.jpg$/', '', $outputPath)));
    }

    /** @test */
    public function it_cleans_up_and_falls_back_when_the_daemon_connection_throws()
    {
        $service = new FakeCoreImageDaemonService;
        $service->behaviour = 'throw';

        $result = $service->enhance($this->inputImagePath, 'adjustable_auto_levels');

        $this->assertNotNull($result);

        $outputPath = $service->requests[0]['outputPath'];
        $this->assertFalse(file_exists($outputPath));
        $this->assertFalse(file_exists((string) preg_replace('/\.jpg$/', '', $outputPath)));
    }

    /** @test */
    public function it_surfaces_unsupported_advanced_tone_mapping_instead_of_returning_unchanged_output()
    {
        $service = new ImageEnhancementService;
        $before = File::get($this->inputImagePath);

        try {
            $service->enhance($this->inputImagePath, 'advanced_tone_mapping', [
                'tone_mapping_shadow_amount' => 30.0,
            ]);
            $this->fail('Expected a RuntimeException for shadow adjustment without Core Image.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Core Image', $e->getMessage());
            // The input file itself is never modified on the failure path.
            $this->assertSame($before, File::get($this->inputImagePath));
        }
    }

    /** @test */
    public function it_surfaces_shadow_adjustment_errors_even_after_a_daemon_failure()
    {
        config([
            'proofgen.tone_mapping_shadow_amount' => 30.0,
            'proofgen.tone_mapping_highlight_amount' => 0.0,
        ]);

        $service = new FakeCoreImageDaemonService;
        $service->behaviour = 'error';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Core Image');

        $service->enhance($this->inputImagePath, 'advanced_tone_mapping');
    }

    /** @test */
    public function it_rejects_unknown_methods_without_touching_the_daemon()
    {
        $service = new FakeCoreImageDaemonService;

        try {
            $service->enhance($this->inputImagePath, 'smart_indoor');
            $this->fail('Expected a RuntimeException for an unknown method.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('smart_indoor', $e->getMessage());
            $this->assertCount(0, $service->requests);
        }
    }

    private function createGradientImage(string $path): void
    {
        $width = 100;
        $height = 100;
        $image = imagecreatetruecolor($width, $height);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $t = ($x + $y) / (($width - 1) + ($height - 1));
                $value = (int) round(60 + $t * 140);
                $color = imagecolorallocate($image, $value, $value, $value);
                imagesetpixel($image, $x, $y, $color);
            }
        }

        imagejpeg($image, $path, 95);
        imagedestroy($image);
    }
}
