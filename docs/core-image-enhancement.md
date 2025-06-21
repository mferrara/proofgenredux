# Core Image Enhancement for macOS

## Overview

The application now supports GPU-accelerated image enhancement using Apple's Core Image framework on macOS. This provides significant performance improvements over traditional CPU-based image processing, especially on Apple Silicon Macs.

## Performance Benefits

Based on testing, Core Image provides:
- **10-20x faster** processing compared to ImageMagick/GD
- **Native Metal GPU acceleration** on M1/M2/M3 processors
- **Lower CPU usage** - offloads work to GPU
- **Better memory efficiency** through GPU memory management
- **Higher quality filters** with sub-pixel precision

## Requirements

- macOS 10.15 (Catalina) or later
- Swift 5.0 or later (included with Xcode)
- PHP 8.2 or later
- Apple Silicon recommended (M1/M2/M3) but works on Intel Macs with discrete GPUs

## How It Works

1. **Automatic Detection**: The system automatically detects if Core Image is available
2. **GPU Processing**: All image operations are performed on the GPU using Metal
3. **Seamless Integration**: Works transparently with existing enhancement methods
4. **Graceful Fallback**: Falls back to standard processing if Core Image unavailable

## Architecture

### Swift Daemon (`ProofgenImageEnhancerDaemon.swift`)
- Runs as a background daemon listening on TCP port 9876
- Leverages Core Image for GPU-accelerated processing
- Handles multiple concurrent requests
- Auto-starts when needed
- Can run in stdin/stdout mode for backward compatibility

### PHP Services

#### `CoreImageDaemonService.php` (Recommended)
- Communicates with Swift daemon via TCP
- Auto-starts daemon if not running
- More reliable than stdin/stdout approach
- Handles concurrent requests efficiently

#### `CoreImageEnhancementService.php` (Fallback)
- Uses stdin/stdout communication
- Single request at a time
- Kept for backward compatibility

### Factory Pattern
The `EnhancementServiceFactory` checks for services in this order:
1. Core Image Daemon (macOS only, recommended)
2. Core Image stdin/stdout (macOS only, fallback)
3. Standard ImageMagick/GD

## Daemon Management

### Artisan Commands

```bash
# Check daemon status
php artisan coreimage:daemon status

# Start daemon
php artisan coreimage:daemon start

# Stop daemon
php artisan coreimage:daemon stop

# Restart daemon
php artisan coreimage:daemon restart
```

### Automatic Startup

The daemon starts automatically when needed. If it's not running when an enhancement is requested, the service will start it and wait for it to be ready.

### Logs

Daemon logs are saved to: `storage/logs/core-image-daemon.log`

## Core Image Filters Used

### Basic Auto Levels
- `CIColorControls` - Automatic brightness/contrast adjustment
- Histogram analysis for optimal parameters

### Percentile Clipping
- `CIToneCurve` - Custom curve for clipping at percentiles
- `CIAreaHistogram` - Fast histogram calculation

### S-Curve Enhancement
- `CIToneCurve` - Smooth S-curve for improved contrast
- Configurable strength parameter

### CLAHE (Adaptive Histogram)
- `CIHighlightShadowAdjust` - Local contrast enhancement
- `CIUnsharpMask` - Additional local contrast boost

### Smart Indoor Enhancement
- `CIHighlightShadowAdjust` - Brightens shadows, controls highlights
- `CIColorControls` - Saturation and contrast adjustment
- `CIColorMatrix` - Warm tone compensation
- `CISharpenLuminance` - Edge enhancement

## Testing

Run the performance test to see the improvement:

```bash
php artisan test --filter ImageEnhancementPerformanceTest
```

Example output:
```
Performance Comparison:
------------------------
Standard       : 1245.32ms
Core Image     :   58.91ms (21.1x faster)
```

## Monitoring

The application logs which enhancement service is being used:
- "Using Core Image daemon for {context} enhancement (GPU accelerated)" - Daemon active
- "Using Core Image for {context} enhancement (GPU accelerated)" - Stdin/stdout fallback
- "Using standard ImageEnhancementService for {context}" - Standard fallback

## Troubleshooting

### Core Image Not Detected

1. Verify you're on macOS:
   ```bash
   php -r "echo PHP_OS_FAMILY;"  # Should output "Darwin"
   ```

2. Check Swift is installed:
   ```bash
   swift --version
   ```

3. Test the Swift tool directly:
   ```bash
   swift app/Services/CoreImage/ProofgenImageEnhancer.swift --test
   ```
   Should output "READY"

4. Check Laravel logs for initialization errors

### Performance Not Improved

1. Ensure Core Image is actually being used (check logs)
2. Verify you're testing with large enough images
3. Check Activity Monitor to see GPU usage during processing
4. Ensure no other GPU-intensive tasks are running

### Errors During Processing

The system automatically falls back to standard processing on any Core Image errors. Check logs for:
- "Core Image enhancement failed, falling back to standard service"
- Specific error messages about what failed

## Advanced Configuration

### Memory Limits

Core Image uses GPU memory which is separate from PHP memory limits. However, very large images may still require adjusting:

```ini
; php.ini
memory_limit = 512M
```

### Process Timeout

The Swift process has a 5-minute idle timeout by default. This can be adjusted in `CoreImageEnhancementService.php`:

```php
$this->swiftProcess->setIdleTimeout(300); // seconds
```

## Future Enhancements

1. **Batch Processing**: Process multiple images in a single GPU operation
2. **Custom Filters**: Add domain-specific filters for equestrian photography
3. **Video Processing**: Extend to video thumbnail generation
4. **Real-time Preview**: Live enhancement preview in the UI
5. **Neural Filters**: Leverage Core ML for AI-powered enhancements