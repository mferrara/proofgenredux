<?php

namespace App\Services;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Image as InterventionImage;
use Intervention\Image\ImageManager;
use RuntimeException;

/**
 * CPU (GD) image enhancement fallback.
 *
 * This is used whenever the macOS Core Image daemon is unavailable (or the
 * host is not macOS). It intentionally implements only the operations it can
 * perform faithfully:
 *
 *   - basic_auto_levels / adjustable_auto_levels: optional global luminance
 *     percentile levels stretch (only when a non-default black/white point is
 *     set, matching the daemon) plus brightness/contrast adjustment.
 *   - percentile_clipping: global luminance percentile stretch.
 *   - advanced_tone_mapping: percentile stretch and midtone gamma. Local
 *     shadow/highlight adjustments require Core Image and raise a clear error
 *     when that renderer is unavailable.
 *
 * Methods/parameters that cannot be applied faithfully throw a
 * RuntimeException so the Settings preview and job logs surface the failure
 * rather than reporting success on unmodified output.
 */
class ImageEnhancementService
{
    protected ImageManager $manager;

    /**
     * Built-in defaults per supported method. Saved proofgen.* configuration
     * overrides these for config-backed methods when the caller does not pass
     * an explicit value (previews pass explicit values).
     *
     * @var array<string, array<string, float>>
     */
    public const PARAMETER_DEFAULTS = [
        'basic_auto_levels' => [
            'auto_levels_target_brightness' => 128.0,
            'auto_levels_contrast_threshold' => 200.0,
            'auto_levels_contrast_boost' => 1.2,
            'auto_levels_black_point' => 0.0,
            'auto_levels_white_point' => 100.0,
        ],
        'adjustable_auto_levels' => [
            'auto_levels_target_brightness' => 128.0,
            'auto_levels_contrast_threshold' => 200.0,
            'auto_levels_contrast_boost' => 1.2,
            'auto_levels_black_point' => 0.0,
            'auto_levels_white_point' => 100.0,
        ],
        'percentile_clipping' => [
            'tone_mapping_percentile_low' => 0.1,
            'tone_mapping_percentile_high' => 99.9,
        ],
        'advanced_tone_mapping' => [
            'tone_mapping_percentile_low' => 0.1,
            'tone_mapping_percentile_high' => 99.9,
            'tone_mapping_shadow_amount' => 0.0,
            'tone_mapping_highlight_amount' => 0.0,
            'tone_mapping_shadow_radius' => 30.0,
            'tone_mapping_midtone_gamma' => 1.0,
        ],
    ];

    public function __construct()
    {
        $this->manager = new ImageManager(GdDriver::class);
    }

    /**
     * Apply enhancement to an image based on the configured method.
     *
     * @param  string  $imagePath  Path to the image file
     * @param  string  $method  Enhancement method to use
     * @param  array  $parameters  Explicit parameters (preview values); saved
     *                             config is used for anything not supplied here
     *
     * @throws RuntimeException when the method is unknown or cannot be faithfully applied
     */
    public function enhance(string $imagePath, string $method, array $parameters = []): InterventionImage
    {
        $this->assertSupportedMethod($method);

        $parameters = $this->resolveParameters($method, $parameters);

        return match ($method) {
            'basic_auto_levels', 'adjustable_auto_levels' => $this->applyAutoLevels($imagePath, $parameters),
            'percentile_clipping' => $this->applyPercentileClipping($imagePath, $parameters),
            'advanced_tone_mapping' => $this->applyAdvancedToneMapping($imagePath, $parameters),
        };
    }

    /**
     * Get the enhancement methods this service can actually apply.
     */
    public static function getAvailableMethods(): array
    {
        return [
            'basic_auto_levels' => 'Basic Auto-Levels',
            'adjustable_auto_levels' => 'Adjustable Auto-Levels',
            'percentile_clipping' => 'Percentile Clipping',
            'advanced_tone_mapping' => 'Advanced Tone Mapping',
        ];
    }

    /**
     * @throws RuntimeException
     */
    protected function assertSupportedMethod(string $method): void
    {
        if (! array_key_exists($method, self::PARAMETER_DEFAULTS)) {
            throw new RuntimeException(sprintf(
                "Unknown image enhancement method '%s'. Supported methods: %s.",
                $method,
                implode(', ', array_keys(self::PARAMETER_DEFAULTS))
            ));
        }
    }

    /**
     * Merge explicit parameters, saved proofgen config, and built-in defaults.
     *
     * Precedence: explicit parameter > saved config > built-in default.
     * basic_auto_levels is a legacy pure-defaults alias and never reads the
     * adjustable config values.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    protected function resolveParameters(string $method, array $parameters): array
    {
        foreach (['percentile_low' => 'tone_mapping_percentile_low', 'percentile_high' => 'tone_mapping_percentile_high'] as $legacy => $current) {
            if (array_key_exists($legacy, $parameters) && ! array_key_exists($current, $parameters)) {
                $parameters[$current] = $parameters[$legacy];
            }
        }

        $defaults = self::PARAMETER_DEFAULTS[$method] ?? [];
        $allowConfig = $method !== 'basic_auto_levels';

        $resolved = [];

        foreach ($defaults as $key => $default) {
            if (array_key_exists($key, $parameters)) {
                $resolved[$key] = $parameters[$key];

                continue;
            }

            $configured = $allowConfig ? config('proofgen.'.$key) : null;
            $resolved[$key] = $configured ?? $default;
        }

        // Preserve any additional caller-supplied parameters (legacy keys,
        // future options) so older callers keep working.
        foreach ($parameters as $key => $value) {
            $resolved[$key] = $value;
        }

        if ($method === 'advanced_tone_mapping' && (float) $resolved['tone_mapping_highlight_amount'] > 0) {
            throw new RuntimeException('Highlight adjustment supports values from -100 to 0. Positive highlight brightening is not supported.');
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    protected function applyAutoLevels(string $imagePath, array $parameters): InterventionImage
    {
        return $this->runGdEnhancement($imagePath, fn (\GdImage $source): \GdImage => $this->autoLevelsImage($source, $parameters));
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    protected function applyPercentileClipping(string $imagePath, array $parameters): InterventionImage
    {
        $low = (float) ($parameters['tone_mapping_percentile_low'] ?? $parameters['percentile_low'] ?? 0.1);
        $high = (float) ($parameters['tone_mapping_percentile_high'] ?? $parameters['percentile_high'] ?? 99.9);

        return $this->runGdEnhancement($imagePath, function (\GdImage $source) use ($low, $high): \GdImage {
            return $this->levelStretchImage($source, $low, $high);
        });
    }

    /**
     * @param  array<string, mixed>  $parameters
     *
     * @throws RuntimeException when local shadow/highlight adjustments are requested
     */
    protected function applyAdvancedToneMapping(string $imagePath, array $parameters): InterventionImage
    {
        $shadow = (float) ($parameters['tone_mapping_shadow_amount'] ?? 0.0);
        $highlight = (float) ($parameters['tone_mapping_highlight_amount'] ?? 0.0);
        $gamma = (float) ($parameters['tone_mapping_midtone_gamma'] ?? 1.0);

        if ($shadow != 0.0 || $highlight != 0.0) {
            throw new RuntimeException(sprintf(
                'Advanced Tone Mapping adjustments (shadow=%s, highlight=%s, gamma=%s) require the macOS Core Image daemon. '.
                'The GD fallback supports percentile stretch and gamma, but not local shadow/highlight adjustments. '.
                'Install/start Core Image (Xcode Command Line Tools) or set shadow/highlight adjustments to 0.',
                $shadow,
                $highlight,
                $gamma
            ));
        }

        $low = (float) ($parameters['tone_mapping_percentile_low'] ?? $parameters['percentile_low'] ?? 0.1);
        $high = (float) ($parameters['tone_mapping_percentile_high'] ?? $parameters['percentile_high'] ?? 99.9);

        return $this->runGdEnhancement($imagePath, function (\GdImage $source) use ($low, $high, $gamma): \GdImage {
            return $this->levelStretchImage($source, $low, $high, $gamma);
        });
    }

    /** Apply the GD fallback in memory, preserving decoder orientation. */
    protected function runGdEnhancement(string $imagePath, callable $transform): InterventionImage
    {
        $source = $this->decodeGd($imagePath);

        return $this->manager->decode($transform($source));
    }

    /**
     * Global luminance auto-levels matching the Core Image daemon's global
     * pass: conditional percentile levels stretch, then brightness and
     * contrast.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function autoLevelsImage(\GdImage $source, array $parameters): \GdImage
    {
        $targetBrightness = (float) ($parameters['auto_levels_target_brightness'] ?? 128.0);
        $contrastThreshold = (float) ($parameters['auto_levels_contrast_threshold'] ?? 200.0);
        $contrastBoost = (float) ($parameters['auto_levels_contrast_boost'] ?? 1.2);
        $blackPoint = (float) ($parameters['auto_levels_black_point'] ?? 0.0);
        $whitePoint = (float) ($parameters['auto_levels_white_point'] ?? 100.0);

        [$histogram, $total] = $this->luminanceHistogram($source);

        // The Core Image daemon only applies the percentile levels pass when a
        // non-default black/white point is set (blackPoint > 0 or
        // whitePoint < 100). Matching that keeps the GD fallback visually
        // consistent with the daemon instead of inventing a default stretch.
        $applyLevels = $blackPoint > 0.0 || $whitePoint < 100.0;

        if ($applyLevels) {
            [$low, $high] = $this->percentileRange($histogram, $total, $blackPoint, $whitePoint);
        } else {
            [$low, $high] = [0.0, 1.0];
        }

        // Measure brightness/contrast on the level-stretched image, matching the
        // Core Image daemon (which runs CIColorControls on the post-levels image).
        $stretchedHistogram = array_fill(0, 256, 0);
        for ($i = 0; $i < 256; $i++) {
            if ($histogram[$i] <= 0) {
                continue;
            }

            $stretchedHistogram[$this->toByte($this->normalizeLevel($i, $low, $high))] += $histogram[$i];
        }

        [$mean, $min, $max] = $this->histogramStats($stretchedHistogram, $total, $targetBrightness);

        $brightness = ($targetBrightness - $mean) / 255.0;
        $contrast = ($max - $min) < $contrastThreshold ? $contrastBoost : 1.0;

        return $this->mapChannels($source, function (int $value) use ($low, $high, $brightness, $contrast): int {
            $normalized = $this->normalizeLevel($value, $low, $high);
            $normalized = ($normalized - 0.5) * $contrast + 0.5;
            $normalized += $brightness;

            return $this->toByte($normalized);
        });
    }

    /**
     * Global luminance percentile stretch.
     */
    protected function levelStretchImage(\GdImage $source, float $lowPercentile, float $highPercentile, float $gamma = 1.0): \GdImage
    {
        [$histogram, $total] = $this->luminanceHistogram($source);
        [$low, $high] = $this->percentileRange($histogram, $total, $lowPercentile, $highPercentile);

        return $this->mapChannels($source, function (int $value) use ($low, $high, $gamma): int {
            return $this->toByte($this->normalizeLevel($value, $low, $high) ** $gamma);
        });
    }

    /**
     * Rec.601 luminance histogram (0-255) and total sampled pixels.
     *
     * @return array{0: array<int, int>, 1: int}
     */
    protected function luminanceHistogram(\GdImage $image): array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $histogram = array_fill(0, 256, 0);
        $pixelCount = 0;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $luminance = (int) round(0.299 * $r + 0.587 * $g + 0.114 * $b);
                $histogram[max(0, min(255, $luminance))]++;
                $pixelCount++;
            }
        }

        return [$histogram, $pixelCount];
    }

    /**
     * Return the normalized [0,1] luminance values at the requested
     * percentiles.
     *
     * @param  array<int, int>  $histogram
     * @return array{0: float, 1: float}
     */
    protected function percentileRange(array $histogram, int $total, float $lowPercentile, float $highPercentile): array
    {
        if ($total <= 0) {
            return [0.0, 1.0];
        }

        $lowPercentile = max(0.0, min(100.0, $lowPercentile));
        $highPercentile = max(0.0, min(100.0, $highPercentile));

        $lowTarget = $total * $lowPercentile / 100.0;
        $highTarget = $total * $highPercentile / 100.0;

        $cumulative = 0;
        $lowIndex = 0;
        $highIndex = 255;
        $lowFound = false;

        for ($i = 0; $i < 256; $i++) {
            $cumulative += $histogram[$i];

            // Require a non-empty bin so a 0% low target resolves to the
            // darkest pixel present rather than 0.
            if (! $lowFound && $histogram[$i] > 0 && $cumulative >= $lowTarget) {
                $lowIndex = $i;
                $lowFound = true;
            }

            if ($cumulative >= $highTarget) {
                $highIndex = $i;

                break;
            }
        }

        return [$lowIndex / 255.0, $highIndex / 255.0];
    }

    /**
     * Mean luminance plus nonzero histogram min/max.
     *
     * @param  array<int, int>  $histogram
     * @return array{0: float, 1: int, 2: int}
     */
    protected function histogramStats(array $histogram, int $total, float $defaultMean): array
    {
        if ($total <= 0) {
            return [$defaultMean, 0, 255];
        }

        $sum = 0;
        $count = 0;
        $min = 255;
        $max = 0;

        for ($i = 0; $i < 256; $i++) {
            $channelCount = $histogram[$i];

            if ($channelCount <= 0) {
                continue;
            }

            $sum += $i * $channelCount;
            $count += $channelCount;
            $min = min($min, $i);
            $max = max($max, $i);
        }

        return [$count > 0 ? $sum / $count : $defaultMean, $min, $max];
    }

    /**
     * Apply a per-channel mapping, preserving the source alpha channel.
     */
    protected function mapChannels(\GdImage $source, callable $map): \GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $destination = imagecreatetruecolor($width, $height);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($source, $x, $y);
                $alpha = ($rgb >> 24) & 0x7F;
                $r = $map(($rgb >> 16) & 0xFF);
                $g = $map(($rgb >> 8) & 0xFF);
                $b = $map($rgb & 0xFF);

                imagesetpixel($destination, $x, $y, ($alpha << 24) | ($r << 16) | ($g << 8) | $b);
            }
        }

        return $destination;
    }

    /**
     * Map a 0-255 channel value onto [0,1] using the supplied percentile range.
     */
    protected function normalizeLevel(int $value, float $low, float $high): float
    {
        $normalized = $value / 255.0;

        if ($high > $low) {
            $normalized = ($normalized - $low) / ($high - $low);
        }

        return max(0.0, min(1.0, $normalized));
    }

    protected function toByte(float $normalized): int
    {
        return (int) max(0, min(255, (int) round($normalized * 255.0)));
    }

    /** Decode through Intervention so EXIF orientation matches production. */
    protected function decodeGd(string $imagePath): \GdImage
    {
        try {
            return $this->manager->decodePath($imagePath)->core()->native();
        } catch (\Throwable $e) {
            throw new RuntimeException("Image enhancement could not decode input image: {$imagePath}", 0, $e);
        }
    }

    /**
     * Create a real temporary path with the desired .jpg suffix.
     *
     * tempnam() creates a file without the suffix; that file is removed here so
     * callers receive a suffix-correct path and never leak the suffix-less file.
     */
    protected function createTempJpegPath(string $prefix): string
    {
        $base = tempnam(sys_get_temp_dir(), $prefix);

        if ($base === false) {
            throw new RuntimeException('Image enhancement could not create a temporary file.');
        }

        $path = $base.'.jpg';
        @unlink($base);

        return $path;
    }
}
