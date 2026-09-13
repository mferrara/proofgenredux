<?php

namespace Tests\Feature;

use App\Livewire\ConfigComponent;
use App\Models\Configuration;
use App\Services\CoreImageDaemonService;
use App\Services\ImageEnhancementService;
use Illuminate\Support\Facades\File;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Regression guard for Settings previews of an 8192x5464 (45 MP) original.
 *
 * Core Image renders out of process, but PHP still decodes the full-resolution
 * result (~180 MB), which exceeds a fresh request's 128M limit. The renderer
 * now raises a request-local allowance sized from the source pixels and
 * restores it after success. The fixture is built in a larger-budget parent; the child runs the
 * real renderer under 128M in a fresh Pest process, mocking only the native
 * Core Image enhancer so it still performs the real full-size decode.
 */
class SettingsLargePreviewTest extends TestCase
{
    private const CHILD_ENV = 'PROOFGEN_LARGE_PREVIEW_CHILD';

    private const SOURCE_ENV = 'PROOFGEN_LARGE_PREVIEW_SOURCE';

    private const DIR_ENV = 'PROOFGEN_LARGE_PREVIEW_DIR';

    public function test_45mp_preview_decodes_under_a_fresh_128m_limit(): void
    {
        if (getenv(self::CHILD_ENV) !== '1') {
            $tempRoot = storage_path('app/settings-large-preview-'.uniqid());
            File::makeDirectory($tempRoot, 0755, true);
            $source = $tempRoot.'/large-source.jpg';

            $parentLimit = ini_get('memory_limit');
            ini_set('memory_limit', '1024M');

            try {
                $this->writeLargeJpeg($source, 8192, 5464);

                $process = new Process(
                    [PHP_BINARY, base_path('vendor/bin/pest'), __FILE__, '--compact'],
                    base_path(),
                    [
                        self::CHILD_ENV => '1',
                        self::SOURCE_ENV => $source,
                        self::DIR_ENV => $tempRoot,
                    ],
                );
                $process->setTimeout(120)->run();
                $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            } finally {
                ini_set('memory_limit', $parentLimit);
                File::deleteDirectory($tempRoot);
            }

            return;
        }

        $source = getenv(self::SOURCE_ENV);
        $tempRoot = getenv(self::DIR_ENV);
        if (! is_string($source) || ! is_file($source) || ! is_string($tempRoot)) {
            $this->fail('Large preview child is missing its fixture paths.');
        }

        $size = getimagesize($source);
        expect($size)->toBeArray()
            ->and($size[0])->toBe(8192)
            ->and($size[1])->toBe(5464);

        $previousLimit = ini_get('memory_limit');
        ini_set('memory_limit', '128M');

        try {
            $component = $this->makeComponent($source);

            // The destination must contain "_preview_" because the renderer
            // derives the comparison with
            // str_replace('_preview_', '_preview_unenhanced_', $destPath).
            $dest = $tempRoot.'/large_preview_test.jpg';
            $result = $this->invoke($component, $source, $dest, 'thumbnails', 'large', true);

            expect($result['enhancement']['enabled'])->toBeTrue()
                ->and($result['input_settings']['width'])->toBe(2048)
                ->and($result['input_settings']['height'])->toBe(1366);

            $unenhanced = $tempRoot.'/large_preview_unenhanced_test.jpg';
            expect(is_file($dest))->toBeTrue()->and(is_file($unenhanced))->toBeTrue();

            foreach ([$dest, $unenhanced] as $file) {
                $info = getimagesize($file);
                expect($info[0])->toBe(911)->and($info[1])->toBe(1366);
            }

            // The scoped allowance must be gone once the render returns.
            expect(ini_get('memory_limit'))->toBe('128M');

            // Watermark pass: a real font writes the preview and a missing font
            // raises the actionable error without a secondary memory-limit error.
            $component->previewWatermarkEnabled = true;
            $component->configValues[4] = true;  // watermark_proofs
            $component->configValues[1] = false; // no enhancement for the watermark pass

            $font = storage_path('watermark_fonts/Georgia_Bold.ttf');
            if (is_file($font)) {
                config(['proofgen.watermark_font' => $font]);
                $watermarked = $tempRoot.'/watermarked_preview_test.jpg';
                $this->invoke($component, $source, $watermarked, 'thumbnails', 'large', false);
                $info = is_file($watermarked) ? getimagesize($watermarked) : false;
                expect($info)->toBeArray()
                    ->and($info[0])->toBe(911)->and($info[1])->toBe(1366)
                    ->and(ini_get('memory_limit'))->toBe('128M');
            }

            config(['proofgen.watermark_font' => $tempRoot.'/missing-font.ttf']);
            try {
                $this->invoke($component, $source, $tempRoot.'/failure_preview_test.jpg', 'thumbnails', 'large', false);
                $this->fail('Expected a missing watermark font to raise a recoverable error.');
            } catch (\RuntimeException $e) {
                expect($e->getMessage())
                    ->toContain('Watermark font not found')
                    ->toContain('Update Watermark Font in Settings.');
            }
            unset($e);
            gc_collect_cycles();
            gc_mem_caches();
            ini_set('memory_limit', '128M');
            expect(ini_get('memory_limit'))->toBe('128M');
        } finally {
            ini_set('memory_limit', $previousLimit);
        }
    }

    public function test_scoped_budget_preserves_unlimited_and_higher_limits(): void
    {
        $tempRoot = storage_path('app/settings-large-preview-limits-'.uniqid());
        File::makeDirectory($tempRoot, 0755, true);
        $source = $tempRoot.'/small_preview_test.jpg';
        $previousLimit = ini_get('memory_limit');

        try {
            $gd = imagecreatetruecolor(64, 48);
            imagefill($gd, 0, 0, imagecolorallocate($gd, 10, 20, 30));
            imagejpeg($gd, $source);
            imagedestroy($gd);

            $component = $this->makeComponent($source);

            foreach (['-1', '1024M'] as $limit) {
                ini_set('memory_limit', $limit);
                $this->invoke($component, $source, $tempRoot.'/limit_preview_test.jpg', 'thumbnails', 'large', false);
                expect(ini_get('memory_limit'))->toBe($limit);
            }
        } finally {
            ini_set('memory_limit', $previousLimit);
            File::deleteDirectory($tempRoot);
        }
    }

    private function invoke(ConfigComponent $component, string $source, string $dest, string $type, ?string $size, bool $generateUnenhanced): array
    {
        $method = new \ReflectionMethod(ConfigComponent::class, 'createPreviewThumbnail');
        $method->setAccessible(true);

        return $method->invoke($component, $source, $dest, $type, $size, $generateUnenhanced);
    }

    private function makeComponent(string $source): ConfigComponent
    {
        // The native Core Image renderer runs out of process; mock only its
        // enhance() so the PHP side still performs a real full-size decode.
        $manager = new ImageManager(GdDriver::class);
        $daemon = \Mockery::mock(CoreImageDaemonService::class);
        $daemon->shouldReceive('isCoreImageAvailable')->andReturnTrue();
        $daemon->shouldReceive('enhance')->andReturnUsing(fn (string $path) => $manager->decodePath($path));
        $this->app->instance(CoreImageDaemonService::class, $daemon);
        $this->app->instance(ImageEnhancementService::class, $daemon);

        config([
            'proofgen.thumbnails.large.width' => 2048,
            'proofgen.thumbnails.large.height' => 1366,
            'proofgen.thumbnails.large.quality' => 82,
            'proofgen.thumbnails.large.font_size' => 12,
            'proofgen.thumbnails.large.bg_size' => 24,
            'proofgen.watermark_foreground_opacity' => 0,
            'proofgen.watermark_background_opacity' => 70,
        ]);

        /** @var ConfigComponent $component */
        $component = (new \ReflectionClass(ConfigComponent::class))->newInstanceWithoutConstructor();
        $component->sampleImagePath = $source;
        $component->previewWatermarkEnabled = false;
        $component->tempThumbnailValues = [
            'thumbnails' => [
                'large' => ['width' => 2048, 'height' => 1366, 'quality' => 82],
            ],
            'web_images' => [],
            'highres_images' => [],
        ];

        $enhancementEnabled = new Configuration(['key' => 'image_enhancement_enabled', 'type' => 'boolean']);
        $enhancementEnabled->id = 1;
        $enhancementMethod = new Configuration(['key' => 'image_enhancement_method', 'type' => 'string']);
        $enhancementMethod->id = 2;
        $applyToProofs = new Configuration(['key' => 'enhancement_apply_to_proofs', 'type' => 'boolean']);
        $applyToProofs->id = 3;
        $watermarkProofs = new Configuration(['key' => 'watermark_proofs', 'type' => 'boolean']);
        $watermarkProofs->id = 4;

        $component->configurationsByCategory = [
            'enhancement' => [$enhancementEnabled, $enhancementMethod, $applyToProofs],
            'watermarks' => [$watermarkProofs],
            'thumbnails' => [],
        ];
        $component->configValues = [1 => true, 2 => 'percentile_clipping', 3 => true, 4 => false];

        return $component;
    }

    private function writeLargeJpeg(string $path, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);

        // A few bands keep the fixture cheap to build while giving the
        // histogram real work and an unambiguous size.
        foreach ([[40, 60, 90], [120, 90, 60], [200, 200, 210]] as $index => [$r, $g, $b]) {
            $x1 = (int) ($index * $width / 3);
            $x2 = (int) min($width - 1, (($index + 1) * $width / 3) - 1);
            imagefilledrectangle($image, $x1, 0, $x2, $height - 1, imagecolorallocate($image, $r, $g, $b));
        }

        imagejpeg($image, $path, 82);
        imagedestroy($image);

        // Camera-style EXIF orientation exercises GD's full-size rotation copies.
        $exif = "Exif\0\0".'II'.pack('vV', 42, 8)
            .pack('v', 1).pack('vvVvv', 0x0112, 3, 1, 6, 0).pack('V', 0);
        $jpeg = file_get_contents($path);
        file_put_contents($path, substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2));
    }
}
