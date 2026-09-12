<?php

namespace App\Proofgen;

use App\Helpers\EnhancementServiceFactory;
use App\Models\Photo;
use App\Services\PathResolver;
use App\Services\PhotoArchiveService;
use App\Services\SafeFileMover;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;
use League\Flysystem\UnableToReadFile;

class Image
{
    // Keep newly drawn watermark text clear after the configured photo-quality pass.
    public const int WATERMARKED_PROOF_QUALITY = 95;

    public string $image_path = '';

    public string $show = '';

    public string $class = '';

    public bool $is_original = false;

    public bool $is_proofed = false;

    public array $missing_proofs = [];

    public bool $rename_files = true;

    public bool $archive_enabled = true;

    public string $filename = '';

    protected PathResolver $pathResolver;

    public function __construct(string $image_path, ?PathResolver $pathResolver = null)
    {
        $this->pathResolver = $pathResolver ?? app(PathResolver::class);
        $this->image_path = $this->pathResolver->normalizePath($image_path);

        // Determine show and class from path
        $path_parts = explode('/', $this->image_path);
        $this->show = $path_parts[0];
        $this->class = $path_parts[1];
        $this->is_original = isset($path_parts[2]) && $path_parts[2] === 'originals';
        $this->filename = end($path_parts);
        $this->rename_files = config('proofgen.rename_files');
        $this->archive_enabled = config('proofgen.archive_enabled');
    }

    public function checkForProofs(): bool
    {
        $proofs_path = $this->pathResolver->getProofsPath($this->show, $this->class);
        $proofs = Storage::disk('fullsize')->files($this->pathResolver->normalizePath($proofs_path));
        $proofed = false;
        $proof_sizes = [];
        foreach (config('proofgen.thumbnails') as $size) {
            $proof_sizes[] = $size['suffix'];
        }

        $proof_sizes_found = [];
        foreach ($proofs as $proof_index => $proof) {
            $proof_array_key = pathinfo($this->filename, PATHINFO_FILENAME);
            foreach ($proof_sizes as $suffix) {
                $proof_filename = pathinfo($proof, PATHINFO_FILENAME);
                $proof_filename = str_replace($suffix, '', $proof_filename);
                if ($proof_filename === pathinfo($this->filename, PATHINFO_FILENAME)) {
                    $proof_sizes_found[$proof_array_key][] = $suffix;
                    break;
                }
            }

            if (isset($proof_sizes_found[$proof_array_key]) && count($proof_sizes_found[$proof_array_key]) === count($proof_sizes)) {
                // Sort $proof_sizes_round[$proof_array_key] and $proof_sizes so they're in the same order
                sort($proof_sizes_found[$proof_array_key]);
                sort($proof_sizes);
                if ($proof_sizes_found[$proof_array_key] === $proof_sizes) {
                    $proofed = true;
                    unset($proofs[$proof_index]);
                    break;
                }
            }
        }
        $this->is_proofed = $proofed;

        $missing_proofs = [];
        $proof_array_key = pathinfo($this->filename, PATHINFO_FILENAME);
        foreach ($proof_sizes as $proof_size) {
            if (! isset($proof_sizes_found[$proof_array_key]) || ! in_array($proof_size, $proof_sizes_found[$proof_array_key])) {
                $missing_proofs[] = $proof_size;
            }
        }

        if (count($missing_proofs)) {
            $this->missing_proofs = $missing_proofs;

            return false;
        }

        $this->missing_proofs = [];

        return true;
    }

    /**
     * Write order is invariant for safety:
     *   1. hash source bytes
     *   2. write archive copy + verify
     *   3. write imported original + verify
     *   4. upsert photos row (with sha1, archive metadata, original_filename)
     *   5. bury ingest source — always last, so a DB or write failure leaves the source recoverable
     */
    public function processImage(string $proof_number, bool $debug = false): Photo
    {
        // 1. Read source bytes once and hash.
        $image = Storage::disk('fullsize')->get($this->image_path);
        $image_sha1 = sha1($image);
        $image_size = strlen($image);

        $original_filename = $this->filename;
        $extension = strtolower(pathinfo($this->filename, PATHINFO_EXTENSION));
        $final_proof_number = $this->rename_files
            ? $proof_number
            : pathinfo($this->filename, PATHINFO_FILENAME);
        $final_filename = $final_proof_number.'.'.$extension;
        $path_to_originals_file = $this->pathResolver->normalizePath(
            $this->pathResolver->getOriginalFilePath($this->show, $this->class, $final_filename)
        );

        // 2. Archive (verified inside storeContents).
        $archiveMetadata = null;
        if ($this->archive_enabled) {
            $archiveService = app(PhotoArchiveService::class);
            $archive_path = $this->pathResolver->normalizePath(
                $archiveService->pathFor($this->show, $this->class, $final_filename)
            );

            $archiveMetadata = $archiveService->storeContents($archive_path, $image);
            if ($debug) {
                Log::debug('Copied file to archive directory; '.$archive_path);
            }
        }

        // 3. Imported original (verified by sha + size, not just existence).
        Storage::disk('fullsize')->put($path_to_originals_file, $image);
        $writtenOriginal = Storage::disk('fullsize')->get($path_to_originals_file);
        if ($writtenOriginal === false || sha1($writtenOriginal) !== $image_sha1 || strlen($writtenOriginal) !== $image_size) {
            throw new \Exception('Original verification failed after write; '.$path_to_originals_file);
        }
        if ($debug) {
            Log::debug('Wrote and verified original; '.$path_to_originals_file);
        }

        if ($this->rename_files) {
            $this->filename = $final_filename;
        }

        // 4. Photos row first — DB failure here must leave source untouched.
        $photo = self::importPhoto(
            $final_proof_number,
            $extension,
            $this->show,
            $this->class,
            $image_sha1,
            $archiveMetadata,
            $original_filename,
        );

        // 5. Bury ingest source LAST.
        $burial = app(SafeFileMover::class)->bury(
            disk: 'fullsize',
            path: $this->image_path,
            reason: SafeFileMover::REASON_POST_IMPORT_SOURCE,
            context: [
                'sha1' => $image_sha1,
                'size' => $image_size,
                'photo_id' => $photo->id,
                'original_filename' => $original_filename,
            ],
        );

        if ($debug) {
            Log::debug('Buried ingest source; '.$this->image_path.' → '.$burial['graveyard_path']);
        }

        return $photo;
    }

    public static function importPhoto(string $proof_number, string $file_type, string $show_id, string $show_class_id, ?string $sha1 = null, ?array $archiveMetadata = null, ?string $originalFilename = null): Photo
    {
        $photo_id = $show_id.'_'.$show_class_id.'_'.$proof_number;
        $photo = Photo::find($photo_id);
        if (! $photo) {
            // If the image doesn't exist, create it
            $photo = new Photo;
            $photo->show_class_id = $show_id.'_'.$show_class_id;
            $photo->proof_number = $proof_number;
            $photo->file_type = $file_type;
            $photo->sha1 = $sha1;
            $photo->original_filename = $originalFilename;
            $photo->save();
        } else {
            $patch = [];
            if ($sha1 && empty($photo->sha1)) {
                $patch['sha1'] = $sha1;
            }
            if ($originalFilename && empty($photo->original_filename)) {
                $patch['original_filename'] = $originalFilename;
            }
            if ($patch !== []) {
                $photo->forceFill($patch)->save();
            }
        }

        if ($archiveMetadata) {
            $photo->forceFill([
                'archive_path' => $archiveMetadata['archive_path'],
                'archive_sha1' => $archiveMetadata['archive_sha1'],
                'archive_size' => $archiveMetadata['archive_size'],
                'archived_at' => $archiveMetadata['archived_at'],
            ])->save();
        }

        return $photo;
    }

    public static function createWebImage($full_size_image_path, $web_dest_path): string
    {
        // Get PathResolver from the container
        $pathResolver = app(PathResolver::class);

        // Normalize the paths
        $full_size_image_path = $pathResolver->normalizePath($full_size_image_path);
        $web_dest_path = $pathResolver->normalizePath($web_dest_path);

        // Confirm the $web_dest_path exists, if not, create it
        if (! Storage::disk('fullsize')->exists($web_dest_path)) {
            Storage::disk('fullsize')->makeDirectory($web_dest_path);
        }

        $base_path = config('proofgen.fullsize_home_dir');

        // Use PathResolver to ensure consistent path formatting
        $full_system_path = $pathResolver->getAbsolutePath($full_size_image_path, $base_path);
        $web_dest_system_path = $pathResolver->getAbsolutePath($web_dest_path, $base_path);

        // Check if the file exists
        if (! file_exists($full_system_path)) {
            throw new \Exception("Image file not found at: {$full_system_path}");
        }

        $manager = new ImageManager(GdDriver::class);

        // TODO: The previous version of proofgen used an Intervention/Image method "orientate" to auto-rotate images
        // TODO: based on their exif data. That method is gone, not sure if it's automatically done or just not supported
        // TODO: anymore. We'll see if it causes problems.
        // TODO: (3/30/2025) - Turns out, we know how this works now - the 'orientation' value in the exif data
        // determines if the image is rotated or not. 1 = normal, 3 = 180 degrees, 6 = 90 degrees, 8 = 270 degrees
        // But since we haven't had to change anything here this is likely handled automatically.
        // $image = $manager->decodePath($full_size_image_path)->orientate();

        // Check if enhancement is enabled
        $enhancementEnabled = config('proofgen.image_enhancement_enabled') && config('proofgen.enhancement_apply_to_web');
        $enhancementMethod = config('proofgen.image_enhancement_method', 'basic_auto_levels');

        // Process image with enhancement if enabled
        if ($enhancementEnabled) {
            $enhancementService = EnhancementServiceFactory::getService('web images');
            $image = $enhancementService->enhance($full_system_path, $enhancementMethod);
        } else {
            $image = $manager->decodePath($full_system_path);
        }

        $web_suf = config('proofgen.web_images.suffix');
        $image_filename = pathinfo($full_system_path, PATHINFO_FILENAME);
        $web_thumb_filename = $image_filename.$web_suf.'.jpg';
        $web_thumb_path = $web_dest_system_path.'/'.$web_thumb_filename;

        // Resolve and validate the watermark BEFORE writing any output. A
        // missing/corrupt watermark used to surface only after the scaled
        // image had been written (and then as a GD TypeError from
        // insert(false, ...)), leaving an un-watermarked file on disk that
        // looked like a finished web image.
        $watermark_path = storage_path().'/watermarks/web-image-watermark-2.png';
        if (! is_file($watermark_path) || ! is_readable($watermark_path)) {
            throw new \RuntimeException(
                "Web image watermark is missing or unreadable at {$watermark_path}; refusing to write {$web_thumb_path} without a watermark."
            );
        }

        $watermark = @imagecreatefrompng($watermark_path);
        if (! $watermark instanceof \GdImage) {
            throw new \RuntimeException(
                "Web image watermark could not be decoded from {$watermark_path}; refusing to write {$web_thumb_path} without a watermark."
            );
        }

        try {
            // Save smaller copy of the image that we'll work with
            $image->scale(config('proofgen.web_images.width'), config('proofgen.web_images.height'))
                ->save($web_thumb_path, quality: (int) config('proofgen.web_images.quality'));
            unset($image);

            // Add the watermark/border/whatever it is
            // Add watermark
            $image = $manager->decodePath($web_thumb_path);

            $average_color = self::determineAverageColor($web_thumb_path);
            $darkness = self::determineWatermarkDarknessFromAverageColor($average_color[0], $average_color[1], $average_color[2]);
            if ($darkness === 'light') {
                imagefilter($watermark, IMG_FILTER_NEGATE);
            }

            $image->insert($watermark, x: 0, y: 60, alignment: 'bottom')->save();
        } finally {
            // The GD watermark copy is a temporary resource; release it even
            // when decoding/inserting/saving throws.
            imagedestroy($watermark);
            unset($watermark);
        }

        unset($image);

        $manager = null;
        unset($manager);

        // Return the full path to the output file
        return $web_thumb_path;
    }

    public static function createHighresImage($full_size_image_path, $highres_dest_path): string
    {
        // Get PathResolver from the container
        $pathResolver = app(PathResolver::class);

        // Normalize the paths
        $full_size_image_path = $pathResolver->normalizePath($full_size_image_path);
        $highres_dest_path = $pathResolver->normalizePath($highres_dest_path);

        // Confirm the $highres_dest_path exists, if not, create it
        if (! Storage::disk('fullsize')->exists($highres_dest_path)) {
            Storage::disk('fullsize')->makeDirectory($highres_dest_path);
        }

        $base_path = config('proofgen.fullsize_home_dir');

        // Use PathResolver to ensure consistent path formatting
        $full_system_path = $pathResolver->getAbsolutePath($full_size_image_path, $base_path);
        $highres_dest_system_path = $pathResolver->getAbsolutePath($highres_dest_path, $base_path);

        $manager = new ImageManager(GdDriver::class);

        // Check if enhancement is enabled
        $enhancementEnabled = config('proofgen.image_enhancement_enabled') && config('proofgen.enhancement_apply_to_highres');
        $enhancementMethod = config('proofgen.image_enhancement_method', 'basic_auto_levels');

        // Process image with enhancement if enabled
        if ($enhancementEnabled) {
            $enhancementService = EnhancementServiceFactory::getService('highres images');
            $image = $enhancementService->enhance($full_system_path, $enhancementMethod);
        } else {
            $image = $manager->decodePath($full_system_path);
        }

        $highres_suf = config('proofgen.highres_images.suffix');
        $image_filename = pathinfo($full_system_path, PATHINFO_FILENAME);
        $highres_thumb_filename = $image_filename.$highres_suf.'.jpg';
        $highres_thumb_path = $highres_dest_system_path.'/'.$highres_thumb_filename;

        // Save smaller copy of the image that we'll work with
        $image->scale(config('proofgen.highres_images.width'), config('proofgen.highres_images.height'))
            ->save($highres_thumb_path, quality: (int) config('proofgen.highres_images.quality'));
        unset($image);

        // Add the watermark/border/whatever it is
        // Add watermark
        $image = $manager->decodePath($highres_thumb_path);
        $watermark = imagecreatefrompng(storage_path().'/watermarks/web-image-watermark-2.png');

        $average_color = self::determineAverageColor($highres_thumb_path);
        $darkness = self::determineWatermarkDarknessFromAverageColor($average_color[0], $average_color[1], $average_color[2]);
        if ($darkness === 'light') {
            imagefilter($watermark, IMG_FILTER_NEGATE);
        }

        $image->insert($watermark, x: 0, y: 60, alignment: 'bottom')->save();

        unset($image);

        $manager = null;
        unset($manager);

        // Return the full path to the output file
        return $highres_thumb_path;
    }

    public static function determineAverageColor(string $image_path): array
    {
        $image = imagecreatefromjpeg($image_path);
        $width = imagesx($image);
        $height = imagesy($image);
        // Calculate the height of the bottom 20% portion
        $bottom_height = (int) ($height * 0.2);
        $r = $g = $b = 0;
        $total = 0;
        for ($y = $height - $bottom_height; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                // Get the color index of the pixel
                $colorIndex = imagecolorat($image, $x, $y);

                // Extract the red, green, and blue values
                $red = ($colorIndex >> 16) & 0xFF;
                $green = ($colorIndex >> 8) & 0xFF;
                $blue = $colorIndex & 0xFF;

                // Add the color values to the sums
                $r += $red;
                $g += $green;
                $b += $blue;

                // Increment the total pixel count
                $total++;
            }
        }
        $r = (int) round($r / $total);
        $g = (int) round($g / $total);
        $b = (int) round($b / $total);

        return [$r, $g, $b];
    }

    public static function determineWatermarkDarknessFromAverageColor($r, $g, $b): string
    {
        $average = (int) ($r + $g + $b) / 3;
        $darkness = 255 - $average;

        if ($darkness > 135) {
            return 'light';
        }

        return 'dark';
    }

    public static function createThumbnails($full_size_image_path, $proofs_dest_path): array|string
    {
        // Get PathResolver from the container
        $pathResolver = app(PathResolver::class);

        $original_full_size_image_path = $full_size_image_path;
        ini_set('memory_limit', '4096M');

        // Normalize paths using PathResolver
        $full_size_image_path = $pathResolver->normalizePath($full_size_image_path);
        $proofs_dest_path = $pathResolver->normalizePath($proofs_dest_path);

        // Get base path from config
        $base_path = config('proofgen.fullsize_home_dir');

        // Use PathResolver to ensure consistent path formatting for filesystem operations
        // Note: Storage::disk('fullsize') will prepend the base path for storage operations
        // but direct filesystem operations need the full path
        $proofs_dest_system_path = $pathResolver->getAbsolutePath($proofs_dest_path, $base_path);

        if ($full_size_image_path !== $original_full_size_image_path) {
            Log::debug('Full size image path was changed from '.$original_full_size_image_path.' to '.$full_size_image_path);
        }

        // Confirm the $proofs_dest_path exists, if not, create it
        if (! Storage::disk('fullsize')->exists($proofs_dest_path)) {
            Storage::disk('fullsize')->makeDirectory($proofs_dest_path);
        }

        // Confirm the $full_size_image_path exists, if not, throw an exception
        if (! Storage::disk('fullsize')->exists($full_size_image_path)) {
            throw new UnableToReadFile('File not found: '.$full_size_image_path);
        }

        $manager = new ImageManager(GdDriver::class);

        // TODO: The previous version of proofgen used an Intervention/Image method "orientate" to auto-rotate images
        // TODO: based on their exif data. That method is gone, not sure if it's automatically done or just not supported
        // TODO: anymore. We'll see if it causes problems.
        // $image = $manager->decodePath($full_size_image_path)->orientate();

        // Use PathResolver.getAbsolutePath to get the correct full system path
        $full_system_path = $pathResolver->getAbsolutePath($full_size_image_path, $base_path);

        // Check if the file exists
        if (! file_exists($full_system_path)) {
            throw new \Exception("Image file not found at: {$full_system_path}");
        }

        // Check if enhancement is enabled
        $enhancementEnabled = config('proofgen.image_enhancement_enabled') && config('proofgen.enhancement_apply_to_proofs');
        $enhancementMethod = config('proofgen.image_enhancement_method', 'basic_auto_levels');

        // Process image with enhancement if enabled
        if ($enhancementEnabled) {
            $enhancementService = EnhancementServiceFactory::getService('thumbnails');
            $image = $enhancementService->enhance($full_system_path, $enhancementMethod);
        } else {
            $image = $manager->decodePath($full_system_path);
        }

        $lrg_suf = config('proofgen.thumbnails.large.suffix');
        $sml_suf = config('proofgen.thumbnails.small.suffix');
        $image_filename = pathinfo($full_size_image_path, PATHINFO_FILENAME);
        $large_thumb_filename = $image_filename.$lrg_suf.'.jpg';
        $small_thumb_filename = $image_filename.$sml_suf.'.jpg';
        $small_thumb_path = $proofs_dest_system_path.'/'.$small_thumb_filename;
        $large_thumb_path = $proofs_dest_system_path.'/'.$large_thumb_filename;
        $do_we_watermark = config('proofgen.watermark_proofs');

        // Save small thumbnail
        $image->scale(config('proofgen.thumbnails.small.width'), config('proofgen.thumbnails.small.height'))
            ->save($small_thumb_path, quality: (int) config('proofgen.thumbnails.small.quality'));
        unset($image);

        // If WATERMARK_PROOFS is true..
        if ($do_we_watermark) {
            // Add watermark
            $image = $manager->decodePath($small_thumb_path);
            $watermark = self::watermarkSmallProof($image_filename);
            $image->insert($watermark, x: 10, y: 10, alignment: 'bottom-left')->save(quality: self::WATERMARKED_PROOF_QUALITY);

            unset($image);
        }

        // Save large thumbnail
        $image = $manager->decodePath($full_system_path);
        $image->scale(config('proofgen.thumbnails.large.width'), config('proofgen.thumbnails.large.height'))
            ->save($large_thumb_path, quality: (int) config('proofgen.thumbnails.large.quality'));
        unset($image);

        // If WATERMARK_PROOFS is true..
        if ($do_we_watermark) {
            // Add watermark
            $image = $manager->decodePath($large_thumb_path);

            if ($image->width() > $image->height()) {
                $text = 'Proof# '.$image_filename.' - Illegal to use - Ferrara Photography';
                $watermark = self::watermarkLargeProof($text, $image->width());
                $image->insert($watermark, alignment: 'center')->save(quality: self::WATERMARKED_PROOF_QUALITY);

            } else {
                $watermark_top = self::watermarkLargeProof('Proof# '.$image_filename.' - Proof# '.$image_filename,
                    $image->width());
                $watermark_bot = self::watermarkLargeProof('Illegal to use - Ferrara Photography', $image->width());

                // $top_offset = round($image->height() * 0.2);
                // $bottom_offset = round($image->height() * 0.2);
                $bottom_offset = round($image->height() * 0.1);

                $image
                    ->insert($watermark_top, alignment: 'center')
                    ->insert($watermark_bot, x: 0, y: $bottom_offset, alignment: 'bottom')
                    ->save(quality: self::WATERMARKED_PROOF_QUALITY);

            }
            unset($image);
        }

        $manager = null;
        unset($manager);

        return $image_filename;
    }

    public static function watermarkSmallProof(string $text, int $width = 0): \GdImage
    {
        $font_size = config('proofgen.thumbnails.small.font_size');
        $background_height = config('proofgen.thumbnails.small.bg_size');
        $foreground_opacity = config('proofgen.watermark_foreground_opacity');
        $background_opacity = config('proofgen.watermark_background_opacity');
        $text = ' '.$text.' ';

        return imagettfJustifytext($text, '', 2, $width, $background_height, 0, 0, $font_size, [255, 255, 255, $foreground_opacity], [0, 0, 0, $background_opacity]);
    }

    public static function watermarkLargeProof(string $text, int $width = 0): \GdImage
    {
        $font_size = config('proofgen.thumbnails.large.font_size');
        $background_height = config('proofgen.thumbnails.large.bg_size');
        $foreground_opacity = config('proofgen.watermark_foreground_opacity');
        $background_opacity = config('proofgen.watermark_background_opacity');

        return imagettfJustifytext($text, '', 2, $width, $background_height, 0, 0, $font_size, [255, 255, 255, $foreground_opacity], [0, 0, 0, $background_opacity]);
    }
}

/**
 * Render text into a freshly-allocated GdImage with the given justification.
 *
 * @param  array  $color  [r, g, b, alpha] for text
 * @param  array  $bgcolor  [r, g, b, alpha] for background
 */
function imagettfJustifytext(string $text, string $font = 'CENTURY.TTF', int $justify = 2, int $W = 0, int $H = 0, int $X = 0, int $Y = 0, int $fsize = 12, array $color = [0x0, 0x0, 0x0, 1], array $bgcolor = [0xFF, 0xFF, 0xFF, 1]): \GdImage
{
    unset($Y); // legacy parameter — kept for signature compatibility, never used
    $font = config('proofgen.watermark_font');

    if (! is_string($font) || ! is_file($font) || ! is_readable($font)) {
        throw new \RuntimeException('Watermark font not found or unreadable: '.($font ?: '(not configured)').'. Update Watermark Font in Settings.');
    }

    $angle = 0;
    $L_R_C = $justify;
    $_bx = \imagettfbbox($fsize, 0, $font, $text);

    $W = ($W == 0) ? abs($_bx[2] - $_bx[0]) : $W;    // If Height not initialized by programmer then it will detect and assign perfect height.
    $H = ($H == 0) ? abs($_bx[5] - $_bx[3]) : $H;    // If Width not initialized by programmer then it will detect and assign perfect width.

    $im = @imagecreate($W, $H)
    or exit('Cannot Initialize new GD image stream');

    // imagecolorallocatealpha for the background must be the FIRST color allocated —
    // GD treats the first allocation as the background fill color.
    imagecolorallocatealpha($im, $bgcolor[0], $bgcolor[1], $bgcolor[2], $bgcolor[3]);
    $text_color = imagecolorallocatealpha($im, $color[0], $color[1], $color[2], $color[3]);

    if ($L_R_C == 0) { // Justify Left
        imagettftext($im, $fsize, $angle, $X, $fsize, $text_color, $font, $text);
    } elseif ($L_R_C == 1) { // Justify Right
        $s = explode("[\n]+", $text);
        $__H = 0;

        foreach ($s as $val) {
            $_b = \imagettfbbox($fsize, 0, $font, $val);
            $_W = abs($_b[2] - $_b[0]);
            // Defining the X coordinate.
            $_X = $W - $_W;
            // Defining the Y coordinate.
            $_H = abs($_b[5] - $_b[3]);
            $__H += $_H;
            imagettftext($im, $fsize, $angle, $_X, $__H, $text_color, $font, $val);
            $__H += 6;
        }
    } elseif ($L_R_C == 2) { // Justify Center
        $s = explode("[\n]+", $text);
        $__H = 0;

        foreach ($s as $val) {
            $_b = \imagettfbbox($fsize, 0, $font, $val);
            $_W = abs($_b[2] - $_b[0]);
            // Defining the X coordinate.
            $_X = abs($W / 2) - abs($_W / 2);
            // Defining the Y coordinate.
            $_H = abs($_b[5] - $_b[3]);
            $__H += $_H;
            imagettftext($im, $fsize, $angle, $_X, $__H, $text_color, $font, $val);
            $__H += 6;
        }
    }

    return $im;
}
