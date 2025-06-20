<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Intervention\Image\Image as InterventionImage;
use Intervention\Image\ImageManager;

class ImageEnhancementService
{
    protected ImageManager $manager;
    protected bool $imagickAvailable;
    
    public function __construct()
    {
        $this->manager = ImageManager::gd();
        $this->imagickAvailable = extension_loaded('imagick') && class_exists('Imagick');
    }
    
    /**
     * Apply enhancement to an image based on the configured method
     * 
     * @param string $imagePath Path to the image file
     * @param string $method Enhancement method to use
     * @param array $parameters Additional parameters for the enhancement
     * @return \Intervention\Image\Image
     */
    public function enhance(string $imagePath, string $method, array $parameters = []): InterventionImage
    {
        $image = $this->manager->read($imagePath);
        
        switch ($method) {
            case 'basic_auto_levels':
                return $this->applyBasicAutoLevels($image);
                
            case 'percentile_clipping':
                $lowPercentile = $parameters['percentile_low'] ?? config('proofgen.enhancement_percentile_low', 0.1);
                $highPercentile = $parameters['percentile_high'] ?? config('proofgen.enhancement_percentile_high', 99.9);
                return $this->applyPercentileClipping($image, $lowPercentile, $highPercentile);
                
            case 'percentile_with_curve':
                $lowPercentile = $parameters['percentile_low'] ?? config('proofgen.enhancement_percentile_low', 0.1);
                $highPercentile = $parameters['percentile_high'] ?? config('proofgen.enhancement_percentile_high', 99.9);
                return $this->applyPercentileWithCurve($image, $lowPercentile, $highPercentile);
                
            case 'clahe':
                $clipLimit = $parameters['clahe_clip_limit'] ?? config('proofgen.enhancement_clahe_clip_limit', 2.0);
                $gridSize = $parameters['clahe_grid_size'] ?? config('proofgen.enhancement_clahe_grid_size', 8);
                return $this->applyCLAHE($image, $clipLimit, $gridSize);
                
            case 'smart_indoor':
                return $this->applySmartIndoorEnhancement($image, $parameters);
                
            default:
                Log::warning("Unknown enhancement method: {$method}");
                return $image;
        }
    }
    
    /**
     * Apply basic auto-levels using ImageMagick if available
     */
    protected function applyBasicAutoLevels(InterventionImage $image): InterventionImage
    {
        if ($this->imagickAvailable) {
            // Create a temporary file to work with Imagick
            $tempPath = tempnam(sys_get_temp_dir(), 'enhance_');
            $image->save($tempPath, 100, 'jpg');
            
            try {
                $imagick = new \Imagick($tempPath);
                $imagick->autoLevelImage();
                $imagick->writeImage($tempPath);
                $imagick->destroy();
                
                $image = $this->manager->read($tempPath);
            } catch (\Exception $e) {
                Log::error("ImageMagick auto-levels failed: " . $e->getMessage());
            } finally {
                unlink($tempPath);
            }
            
            return $image;
        }
        
        // Fallback to manual implementation
        return $this->applyManualAutoLevels($image);
    }
    
    /**
     * Manual auto-levels implementation for when ImageMagick is not available
     */
    protected function applyManualAutoLevels(InterventionImage $image): InterventionImage
    {
        // This is a simplified version - in production you might want a more sophisticated approach
        $width = $image->width();
        $height = $image->height();
        
        // Create temporary file for GD manipulation
        $tempPath = tempnam(sys_get_temp_dir(), 'enhance_');
        $image->save($tempPath, 100, 'jpg');
        
        $sourceImage = imagecreatefromjpeg($tempPath);
        
        // Calculate histogram for each channel
        $histogram = ['r' => array_fill(0, 256, 0), 'g' => array_fill(0, 256, 0), 'b' => array_fill(0, 256, 0)];
        
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($sourceImage, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                
                $histogram['r'][$r]++;
                $histogram['g'][$g]++;
                $histogram['b'][$b]++;
            }
        }
        
        // Find min and max values for each channel
        $totalPixels = $width * $height;
        $channels = ['r', 'g', 'b'];
        $minMax = [];
        
        foreach ($channels as $channel) {
            $min = 0;
            $max = 255;
            $sum = 0;
            
            // Find min (first non-zero)
            for ($i = 0; $i < 256; $i++) {
                if ($histogram[$channel][$i] > 0) {
                    $min = $i;
                    break;
                }
            }
            
            // Find max (last non-zero)
            for ($i = 255; $i >= 0; $i--) {
                if ($histogram[$channel][$i] > 0) {
                    $max = $i;
                    break;
                }
            }
            
            $minMax[$channel] = ['min' => $min, 'max' => $max];
        }
        
        // Apply levels adjustment
        $destImage = imagecreatetruecolor($width, $height);
        
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($sourceImage, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                
                // Stretch each channel
                $r = $this->stretchValue($r, $minMax['r']['min'], $minMax['r']['max']);
                $g = $this->stretchValue($g, $minMax['g']['min'], $minMax['g']['max']);
                $b = $this->stretchValue($b, $minMax['b']['min'], $minMax['b']['max']);
                
                $color = imagecolorallocate($destImage, $r, $g, $b);
                imagesetpixel($destImage, $x, $y, $color);
            }
        }
        
        imagejpeg($destImage, $tempPath, 100);
        imagedestroy($sourceImage);
        imagedestroy($destImage);
        
        $image = $this->manager->read($tempPath);
        unlink($tempPath);
        
        return $image;
    }
    
    /**
     * Apply percentile-based clipping
     */
    protected function applyPercentileClipping(InterventionImage $image, float $lowPercentile, float $highPercentile): InterventionImage
    {
        if ($this->imagickAvailable) {
            $tempPath = tempnam(sys_get_temp_dir(), 'enhance_');
            $image->save($tempPath, 100, 'jpg');
            
            try {
                $imagick = new \Imagick($tempPath);
                
                // Get image statistics
                $stats = $imagick->getImageChannelStatistics();
                
                // Calculate percentile values for each channel
                $width = $imagick->getImageWidth();
                $height = $imagick->getImageHeight();
                $totalPixels = $width * $height;
                
                $lowPixel = (int)($totalPixels * $lowPercentile / 100);
                $highPixel = (int)($totalPixels * $highPercentile / 100);
                
                // Apply contrast stretch based on percentiles
                // This is a simplified version - ideally we'd calculate exact percentile values
                $imagick->contrastStretchImage($lowPixel, $highPixel);
                
                $imagick->writeImage($tempPath);
                $imagick->destroy();
                
                $image = $this->manager->read($tempPath);
            } catch (\Exception $e) {
                Log::error("ImageMagick percentile clipping failed: " . $e->getMessage());
            } finally {
                unlink($tempPath);
            }
        }
        
        return $image;
    }
    
    /**
     * Apply percentile clipping with S-curve
     */
    protected function applyPercentileWithCurve(InterventionImage $image, float $lowPercentile, float $highPercentile): InterventionImage
    {
        // First apply percentile clipping
        $image = $this->applyPercentileClipping($image, $lowPercentile, $highPercentile);
        
        if ($this->imagickAvailable) {
            $tempPath = tempnam(sys_get_temp_dir(), 'enhance_');
            $image->save($tempPath, 100, 'jpg');
            
            try {
                $imagick = new \Imagick($tempPath);
                
                // Apply S-curve using sigmoid contrast
                $imagick->sigmoidalContrastImage(true, 3, 0.5);
                
                $imagick->writeImage($tempPath);
                $imagick->destroy();
                
                $image = $this->manager->read($tempPath);
            } catch (\Exception $e) {
                Log::error("ImageMagick S-curve failed: " . $e->getMessage());
            } finally {
                unlink($tempPath);
            }
        }
        
        return $image;
    }
    
    /**
     * Apply CLAHE (Contrast Limited Adaptive Histogram Equalization)
     */
    protected function applyCLAHE(InterventionImage $image, float $clipLimit, int $gridSize): InterventionImage
    {
        if ($this->imagickAvailable) {
            $tempPath = tempnam(sys_get_temp_dir(), 'enhance_');
            $image->save($tempPath, 100, 'jpg');
            
            try {
                $imagick = new \Imagick($tempPath);
                
                // Apply adaptive histogram equalization
                // Note: adaptiveEqualizeImage might not be available in all ImageMagick versions
                if (method_exists($imagick, 'adaptiveEqualizeImage')) {
                    $imagick->adaptiveEqualizeImage($gridSize, $gridSize);
                } else {
                    // Fallback to regular equalization
                    $imagick->equalizeImage();
                }
                
                $imagick->writeImage($tempPath);
                $imagick->destroy();
                
                $image = $this->manager->read($tempPath);
            } catch (\Exception $e) {
                Log::error("ImageMagick CLAHE failed: " . $e->getMessage());
            } finally {
                unlink($tempPath);
            }
        }
        
        return $image;
    }
    
    /**
     * Apply smart indoor enhancement optimized for horse show photography
     */
    protected function applySmartIndoorEnhancement(InterventionImage $image, array $parameters): InterventionImage
    {
        if ($this->imagickAvailable) {
            $tempPath = tempnam(sys_get_temp_dir(), 'enhance_');
            $image->save($tempPath, 100, 'jpg');
            
            try {
                $imagick = new \Imagick($tempPath);
                
                // Step 1: Mild percentile clipping (0.1% - 99.8%)
                $width = $imagick->getImageWidth();
                $height = $imagick->getImageHeight();
                $totalPixels = $width * $height;
                $lowPixel = (int)($totalPixels * 0.001);
                $highPixel = (int)($totalPixels * 0.998);
                $imagick->contrastStretchImage($lowPixel, $highPixel);
                
                // Step 2: Mild adaptive equalization for uneven lighting
                if (method_exists($imagick, 'adaptiveEqualizeImage')) {
                    $imagick->adaptiveEqualizeImage(8, 8);
                } else {
                    // Fallback to regular equalization with reduced effect
                    $imagick->equalizeImage();
                    $imagick->modulateImage(100, 85, 100); // Reduce saturation slightly to compensate
                }
                
                // Step 3: Slight warm tone adjustment (compensate for fluorescent)
                // Reduce blue channel slightly
                $imagick->evaluateImage(\Imagick::EVALUATE_MULTIPLY, 0.98, \Imagick::CHANNEL_BLUE);
                
                // Step 4: Gentle S-curve for contrast
                $imagick->sigmoidalContrastImage(true, 2.5, 0.5);
                
                // Step 5: Slight sharpening to compensate for any softness
                $imagick->unsharpMaskImage(0, 0.5, 1, 0.05);
                
                $imagick->writeImage($tempPath);
                $imagick->destroy();
                
                $image = $this->manager->read($tempPath);
            } catch (\Exception $e) {
                Log::error("ImageMagick smart indoor enhancement failed: " . $e->getMessage());
            } finally {
                unlink($tempPath);
            }
        } else {
            // Fallback to basic auto-levels if ImageMagick not available
            $image = $this->applyManualAutoLevels($image);
        }
        
        return $image;
    }
    
    /**
     * Helper function to stretch a value between min and max to 0-255
     */
    private function stretchValue(int $value, int $min, int $max): int
    {
        if ($max <= $min) {
            return $value;
        }
        
        $stretched = (int)(($value - $min) * 255 / ($max - $min));
        return max(0, min(255, $stretched));
    }
    
    /**
     * Get available enhancement methods
     */
    public static function getAvailableMethods(): array
    {
        return [
            'basic_auto_levels' => 'Basic Auto-Levels',
            'percentile_clipping' => 'Percentile Clipping (0.1%-99.9%)',
            'percentile_with_curve' => 'Percentile Clipping + S-Curve',
            'clahe' => 'CLAHE (Adaptive Histogram Equalization)',
            'smart_indoor' => 'Smart Indoor (Optimized for Horse Shows)'
        ];
    }
}