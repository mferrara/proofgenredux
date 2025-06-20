# Image Enhancement Feature

## Overview
The image enhancement feature automatically improves image quality during processing by applying various histogram equalization and contrast adjustment techniques. This is particularly useful for indoor horse show photography where lighting conditions can be challenging.

## Available Enhancement Methods

### 1. Basic Auto-Levels
- Uses ImageMagick's autoLevelImage() function
- Stretches the histogram to use the full dynamic range
- Good for images with poor contrast

### 2. Percentile Clipping (0.1%-99.9%)
- Clips extreme values to avoid over-correction from dust specs or hot pixels
- Configurable percentile thresholds
- Preserves more natural look than basic auto-levels

### 3. Percentile Clipping + S-Curve
- Applies percentile clipping first
- Then applies a gentle S-curve for improved contrast
- Good balance between enhancement and natural appearance

### 4. CLAHE (Contrast Limited Adaptive Histogram Equalization)
- Divides image into regions and equalizes each independently
- Prevents over-amplification of noise
- Excellent for images with varying lighting conditions

### 5. Smart Indoor (Optimized for Horse Shows)
- Specifically tuned for indoor arena lighting
- Combines multiple techniques:
  - Percentile clipping at 0.1%-99.8%
  - Mild adaptive equalization
  - Slight warm tone adjustment (compensates for fluorescent lights)
  - Gentle S-curve for contrast
  - Preserves skin tones and horse coat colors

## Configuration

Enhancement settings are available in the Application Settings page under "Image Enhancement":

1. **Enable Image Enhancement** - Master toggle for the feature
2. **Enhancement Method** - Select which method to use
3. **Apply To** - Choose which image types to enhance:
   - Proof Thumbnails
   - Web Images
   - High-Resolution Images

### Advanced Settings
- **Percentile Low/High Threshold** - For percentile-based methods
- **CLAHE Clip Limit** - Contrast limiting parameter (1.0-4.0)
- **CLAHE Grid Size** - Grid divisions for adaptive equalization (4-16)

## Usage Tips

1. **Start with Smart Indoor** - This is optimized for typical horse show conditions
2. **Test with Sample Images** - Use the preview feature in settings to see effects
3. **Consider Different Settings** - You might want enhancement only on proofs, not on paid products
4. **Monitor Performance** - Enhancement adds processing time, especially for large batches

## Technical Details

### Requirements
- PHP GD extension (required, always available)
- ImageMagick PHP extension (optional, enables advanced features)

### Fallback Behavior
If ImageMagick is not available:
- Basic auto-levels uses a manual histogram stretching algorithm
- Other methods gracefully degrade to simpler alternatives
- All methods remain functional with reduced capabilities

### Performance Considerations
- Enhancement is applied to full-resolution images before resizing
- Adds approximately 10-20% to processing time
- Memory usage increases slightly during enhancement
- Results are not cached (applied fresh each time)

## Troubleshooting

### Images Look Over-Processed
- Try reducing the percentile range (e.g., 0.5%-99.5%)
- Switch to basic auto-levels or percentile clipping without curve
- Disable enhancement for final products (web/highres)

### Enhancement Not Working
- Check that enhancement is enabled in settings
- Verify the specific image type has enhancement enabled
- Check logs for any ImageMagick errors
- Try basic auto-levels method as a test

### Performance Issues
- Consider disabling enhancement for high-resolution images
- Process smaller batches
- Ensure sufficient memory allocation in PHP settings