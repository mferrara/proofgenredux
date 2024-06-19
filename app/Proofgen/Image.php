<?php

namespace App\Proofgen;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

class Image
{
    public string $image_path = '';
    public string $show = '';
    public string $class = '';
    public bool $is_original = false;
    public bool $is_proofed = false;
    public array $missing_proofs = [];
    public bool $rename_files = true;

    public string $filename = '';

    public function __construct(string $image_path)
    {
        $this->image_path = $image_path;

        // Determine show and class from path
        $path_parts = explode('/', $image_path);
        $this->show = $path_parts[0];
        $this->class = $path_parts[1];
        $this->is_original = isset($path_parts[2]) && $path_parts[2] === 'originals';
        $this->filename = end($path_parts);
        $this->rename_files = config('proofgen.rename_files');
    }

    public function checkForProofs(): bool
    {
        $proofs_path = '/proofs/'.$this->show.'/'.$this->class;
        $proofs = Storage::disk('fullsize')->files($proofs_path);
        $proofed = false;
        $proof_sizes = [];
        foreach(config('proofgen.thumbnails') as $size) {
            $proof_sizes[] = $size['suffix'];
        }

        $proof_sizes_found = [];
        foreach($proofs as $proof_index => $proof) {
            $proof_array_key = pathinfo($this->filename, PATHINFO_FILENAME);
            foreach($proof_sizes as $suffix) {
                $proof_filename = pathinfo($proof, PATHINFO_FILENAME);
                $proof_filename = str_replace($suffix, '', $proof_filename);
                if($proof_filename === pathinfo($this->filename, PATHINFO_FILENAME)) {
                    $proof_sizes_found[$proof_array_key][] = $suffix;
                    break;
                }
            }

            if(isset($proof_sizes_found[$proof_array_key]) && count($proof_sizes_found[$proof_array_key]) === count($proof_sizes)) {
                // Sort $proof_sizes_round[$proof_array_key] and $proof_sizes so they're in the same order
                sort($proof_sizes_found[$proof_array_key]);
                sort($proof_sizes);
                if($proof_sizes_found[$proof_array_key] === $proof_sizes) {
                    $proofed = true;
                    unset($proofs[$proof_index]);
                    break;
                }
            }
        }
        $this->is_proofed = $proofed;

        $missing_proofs = [];
        $proof_array_key = pathinfo($this->filename, PATHINFO_FILENAME);
        foreach($proof_sizes as $proof_size) {
            if( ! isset($proof_sizes_found[$proof_array_key]) || ! in_array($proof_size, $proof_sizes_found[$proof_array_key])) {
                $missing_proofs[] = $proof_size;
            }
        }

        if(count($missing_proofs)) {
            $this->missing_proofs = $missing_proofs;

            return false;
        }

        $this->missing_proofs = [];
        return true;
    }

    public function processImage(string $proof_number, bool $debug = false): string
    {
        // First we'll get the image from the directory
        Log::debug('Processing image with path: '.$this->image_path);
        $image = Storage::disk('fullsize')->get($this->image_path);

        $original_filename = $this->filename;
        $path_to_original_copy = $this->show.'/'.$this->class.'/originals/'.$this->filename;

        // Next, we'll move the image to the original directory
        Storage::disk('fullsize')->put($path_to_original_copy, $image);
        if($debug) {
            Log::debug('Moved image to originals directory; '.$this->image_path);
        }
        // Next, we'll confirm that copied file exists
        $exists = Storage::disk('fullsize')->exists($path_to_original_copy);
        if($debug) {
            Log::debug('File exists in originals directory; '.$this->image_path);
        }

        if( ! $exists) {
            throw new \Exception('File not copied to originals directory; '.$this->image_path);
        }

        // If we're configured to rename files we'll handle that now
        if($this->rename_files) {
            // First, get the extension from the file
            $extension = strtolower(pathinfo($this->filename, PATHINFO_EXTENSION));
            // Next, generate the new filename
            $new_filename = $proof_number.'.'.$extension;
            $new_original_path = $this->show.'/'.$this->class.'/originals/'.$new_filename;
            // Next, rename the file we put in the originals path
            Storage::disk('fullsize')->move($path_to_original_copy, $new_original_path);
            if($debug) {
                Log::debug('Renamed file in originals directory from '.$original_filename.' to '.$new_filename);
            }
            // Now, rename the file in $this->image_path
            $new_path = $this->show.'/'.$this->class.'/'.$new_filename;
            Storage::disk('fullsize')->move($this->image_path, $new_path);

            // Update $this->image_path to reflect the new filename
            // $this->image_path = $new_path;

            if($debug) {
                Log::debug('Renamed file in processing directory from '.$original_filename.' to '.$new_filename);
            }
            $this->filename = $new_filename;
            if($debug) {
                Log::debug('Filename updated to '.$new_filename);
            }
            $this->image_path = $new_path;
            if($debug) {
                Log::debug('Image path updated to '.$new_path);
            }
        }

        // Next we'll copy this file to the archive directory
        $archive_path = $this->show.'/'.$this->class.'/'.$this->filename;

        // First, we'll see if it already exists in the archive from a previous failed run...
        $exists = Storage::disk('archive')->exists($archive_path);
        if($exists){
            if($debug) {
                Log::debug('File already exists in archive directory; '.$archive_path.' - Deleting...');
            }
            Storage::disk('archive')->delete($archive_path);
        }

        Storage::disk('archive')->put($archive_path, $image);
        if($debug) {
            Log::debug('Copied file to archive directory; '.$archive_path);
        }

        // Next we'll confirm this copy of the file
        $exists = Storage::disk('archive')->exists($archive_path);
        if( ! $exists) {
            throw new \Exception('File not copied to archive directory; Tried to write file to: '.$archive_path);
        }
        if($debug) {
            Log::debug('File exists in archive directory; '.$archive_path);
        }

        // Finally, we'll delete the original file
        if(isset($new_path)) {
            Storage::disk('fullsize')->delete($new_path);
        } else {
            Storage::disk('fullsize')->delete($this->image_path);
        }
        if($debug) {
            if(isset($new_path)) {
                Log::debug('Deleted original file; '.$new_path);
            } else {
                Log::debug('Deleted original file; '.$this->image_path);
            }
        }
        // Confirm the file is deleted
        if(isset($new_path)) {
            $exists = Storage::disk('fullsize')->exists($new_path);
        } else {
            $exists = Storage::disk('fullsize')->exists($this->image_path);
        }

        if($exists) {
            if(isset($new_path)) {
                throw new \Exception('File not deleted; '.$new_path);
            } else {
                throw new \Exception('File not deleted; '.$this->image_path);
            }
        }

        if(isset($new_original_path))
            return $new_original_path;

        return $path_to_original_copy;
    }

    public static function createWebImage($full_size_image_path, $web_dest_path): array|string
    {
        // Confirm the $web_dest_path exists, if not, create it
        if( ! Storage::disk('fullsize')->exists($web_dest_path)) {
            Storage::disk('fullsize')->makeDirectory($web_dest_path);
        }

        $base_path = config('proofgen.fullsize_home_dir');
        $full_size_image_path = $base_path.'/'.$full_size_image_path;
        $web_dest_path = $base_path.'/'.$web_dest_path;

        $manager = ImageManager::gd();

        // TODO: The previous version of proofgen used an Intervention/Image method "orientate" to auto-rotate images
        // TODO: based on their exif data. That method is gone, not sure if it's automatically done or just not supported
        // TODO: anymore. We'll see if it causes problems.
        // $image = $manager->read($full_size_image_path)->orientate();
        $image = $manager->read($full_size_image_path);
        $web_suf = config('proofgen.web_images.suffix');
        $image_filename = pathinfo($full_size_image_path, PATHINFO_FILENAME);
        $web_thumb_filename = $image_filename.$web_suf.'.jpg';
        $web_thumb_path = $web_dest_path.'/'.$web_thumb_filename;

        // Save small thumbnail
        $image->scale(config('proofgen.web_images.width'), config('proofgen.web_images.height'))
            ->save($web_thumb_path, config('proofgen.web_images.quality'));
        unset($image);

        // Add the watermark/border/whatever it is
        // Add watermark
        $image = $manager->read($web_thumb_path);
        $watermark = imagecreatefrompng(storage_path().'/watermarks/web-image-watermark-2.png');

        $average_color = self::determineAverageColor($web_thumb_path);
        $darkness = self::determineWatermarkDarknessFromAverageColor($average_color[0], $average_color[1], $average_color[2]);
        if($darkness === 'light') {
            imagefilter($watermark, IMG_FILTER_NEGATE);
        }

        $image->place($watermark, 'bottom', 0, 60)->save();

        imagedestroy($watermark);
        unset($image);

        $manager = null;
        unset($manager);

        return $image_filename;
    }

    public static function determineAverageColor(string $image_path): array
    {
        $image = imagecreatefromjpeg($image_path);
        $width = imagesx($image);
        $height = imagesy($image);
        // Calculate the height of the bottom 20% portion
        $bottom_height = (int)($height * 0.2);
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

        if($darkness > 135) {
            return 'light';
        }

        return 'dark';
    }

    public static function createThumbnails($full_size_image_path, $proofs_dest_path): array|string
    {
        ini_set('memory_limit', '4096M');
        // Confirm the $proofs_dest_path exists, if not, create it
        if( ! Storage::disk('fullsize')->exists($proofs_dest_path)) {
            Storage::disk('fullsize')->makeDirectory($proofs_dest_path);
        }

        $base_path = config('proofgen.fullsize_home_dir');
        $full_size_image_path = $base_path.'/'.$full_size_image_path;
        $proofs_dest_path = $base_path.'/'.$proofs_dest_path;

        $manager = ImageManager::gd();

        // TODO: The previous version of proofgen used an Intervention/Image method "orientate" to auto-rotate images
        // TODO: based on their exif data. That method is gone, not sure if it's automatically done or just not supported
        // TODO: anymore. We'll see if it causes problems.
        // $image = $manager->read($full_size_image_path)->orientate();
        $image = $manager->read($full_size_image_path);
        $lrg_suf = config('proofgen.thumbnails.large.suffix');
        $sml_suf = config('proofgen.thumbnails.small.suffix');
        $image_filename = pathinfo($full_size_image_path, PATHINFO_FILENAME);
        $large_thumb_filename = $image_filename.$lrg_suf.'.jpg';
        $small_thumb_filename = $image_filename.$sml_suf.'.jpg';
        $small_thumb_path = $proofs_dest_path.'/'.$small_thumb_filename;
        $large_thumb_path = $proofs_dest_path.'/'.$large_thumb_filename;
        $do_we_watermark = config('proofgen.watermark_proofs');

        // Save small thumbnail
        $image->scale(config('proofgen.thumbnails.small.width'), config('proofgen.thumbnails.small.height'))
            ->save($small_thumb_path, config('proofgen.thumbnails.small.quality'));
        unset($image);

        // If WATERMARK_PROOFS is true..
        if ($do_we_watermark) {
            // Add watermark
            $image = $manager->read($small_thumb_path);
            $watermark = self::watermarkSmallProof($image_filename);
            $image->place($watermark, 'bottom-left', 10, 10)->save();

            imagedestroy($watermark);
            unset($image);
        }

        // Save large thumbnail
        $image = $manager->read($full_size_image_path);
        $image->scale(config('proofgen.thumbnails.large.width'), config('proofgen.thumbnails.large.height'))
            ->save($large_thumb_path, getenv('LARGE_THUMBNAIL_QUALITY'));
        unset($image);

        // If WATERMARK_PROOFS is true..
        if ($do_we_watermark) {
            // Add watermark
            $image = $manager->read($large_thumb_path);

            if ($image->width() > $image->height()) {
                $text = 'Proof# '.$image_filename.' - Illegal to use - Ferrara Photography';
                $watermark = self::watermarkLargeProof($text, $image->width());
                $image->place($watermark, 'center')->save();

                imagedestroy($watermark);
            } else {
                $watermark_top = self::watermarkLargeProof('Proof# '.$image_filename.' - Proof# '.$image_filename,
                    $image->width());
                $watermark_bot = self::watermarkLargeProof('Illegal to use - Ferrara Photography', $image->width());

                //$top_offset = round($image->height() * 0.2);
                //$bottom_offset = round($image->height() * 0.2);
                $bottom_offset = round($image->height() * 0.1);

                $image
                    //->insert($watermark_top, 'top', 0, $top_offset)
                    ->place($watermark_top, 'center')
                    ->place($watermark_bot, 'bottom', 0, $bottom_offset)
                    ->save();

                imagedestroy($watermark_top);
                imagedestroy($watermark_bot);
            }
            unset($image);
        }

        //echo 'Thumbnails created.'.PHP_EOL;

        $manager = null;
        unset($manager);

        return $image_filename;
    }

    public static function watermarkWebImage()
    {
        $font_size = config('proofgen.web_images.font_size');
        $background_height = config('proofgen.web_images.bg_size');
        $foreground_opacity = config('proofgen.watermark_foreground_opacity');
        $background_opacity = config('proofgen.watermark_background_opacity');
        $im = imagettfJustifytext('Ferrara Photography', '', 2, 0, $background_height, 0, 0, $font_size, [255, 255, 255, $foreground_opacity], [0, 0, 0, $background_opacity]);

        return $im;
    }

    public static function watermarkSmallProof($text, $width = 0)
    {
        $font_size = config('proofgen.thumbnails.small.font_size');
        $background_height = config('proofgen.thumbnails.small.bg_size');
        $foreground_opacity = config('proofgen.watermark_foreground_opacity');
        $background_opacity = config('proofgen.watermark_background_opacity');
        $text = ' '.$text.' ';
        $im = imagettfJustifytext($text, '', 2, $width, $background_height, 0, 0, $font_size, [255, 255, 255, $foreground_opacity], [0, 0, 0, $background_opacity]);

        return $im;
    }

    public static function watermarkLargeProof($text, $width = 0)
    {
        $font_size = config('proofgen.thumbnails.large.font_size');
        $background_height = config('proofgen.thumbnails.large.bg_size');
        $foreground_opacity = config('proofgen.watermark_foreground_opacity');
        $background_opacity = config('proofgen.watermark_background_opacity');
        $im = imagettfJustifytext($text, '', 2, $width, $background_height, 0, 0, $font_size, [255, 255, 255, $foreground_opacity], [0, 0, 0, $background_opacity]);

        return $im;
    }
}

/**
 * @name                    : makeImageF
 *
 * Function for create image from text with selected font.
 *
 * @param  string  $text     : String to convert into the Image.
 * @param  string  $font     : Font name of the text. Kip font file in same folder.
 * @param  int  $justify  : Justify text in image (0-Left, 1-Right, 2-Center)
 * @param  int  $W        : Width of the Image.
 * @param  int  $H        : Height of the Image.
 * @param  int  $X        : x-coordinate of the text into the image.
 * @param  int  $Y        : y-coordinate of the text into the image.
 * @param  int  $fsize    : Font size of text.
 * @param  array  $color       : RGB color array for text color.
 * @param  array  $bgcolor  : RGB color array for background.
 * @return resource $im
 */
function imagettfJustifytext($text, $font = 'CENTURY.TTF', $justify = 2, $W = 0, $H = 0, $X = 0, $Y = 0, $fsize = 12, $color = [0x0, 0x0, 0x0, 1], $bgcolor = [0xFF, 0xFF, 0xFF, 1])
{
    $font = getenv('WATERMARK_FONT');

    $angle = 0;
    $L_R_C = $justify;
    $_bx = \imageTTFBbox($fsize, 0, $font, $text);

    $W = ($W == 0) ? abs($_bx[2] - $_bx[0]) : $W;    //If Height not initialized by programmer then it will detect and assign perfect height.
    $H = ($H == 0) ? abs($_bx[5] - $_bx[3]) : $H;    //If Width not initialized by programmer then it will detect and assign perfect width.

    $im = @imagecreate($W, $H)
    or exit('Cannot Initialize new GD image stream');

    $background_color = imagecolorallocatealpha($im, $bgcolor[0], $bgcolor[1], $bgcolor[2], $bgcolor[3]);        //RGB color background.
    $text_color = imagecolorallocatealpha($im, $color[0], $color[1], $color[2], $color[3]);            //RGB color text.

    if ($L_R_C == 0) { //Justify Left
        imagettftext($im, $fsize, $angle, $X, $fsize, $text_color, $font, $text);
    } elseif ($L_R_C == 1) { //Justify Right
        $s = explode("[\n]+", $text);
        $__H = 0;

        foreach ($s as $key => $val) {
            $_b = \imageTTFBbox($fsize, 0, $font, $val);
            $_W = abs($_b[2] - $_b[0]);
            //Defining the X coordinate.
            $_X = $W - $_W;
            //Defining the Y coordinate.
            $_H = abs($_b[5] - $_b[3]);
            $__H += $_H;
            imagettftext($im, $fsize, $angle, $_X, $__H, $text_color, $font, $val);
            $__H += 6;
        }
    } elseif ($L_R_C == 2) { //Justify Center
        $s = explode("[\n]+", $text);
        $__H = 0;

        foreach ($s as $key => $val) {
            $_b = \imageTTFBbox($fsize, 0, $font, $val);
            $_W = abs($_b[2] - $_b[0]);
            //Defining the X coordinate.
            $_X = abs($W / 2) - abs($_W / 2);
            //Defining the Y coordinate.
            $_H = abs($_b[5] - $_b[3]);
            $__H += $_H;
            imagettftext($im, $fsize, $angle, $_X, $__H, $text_color, $font, $val);
            $__H += 6;
        }
    }

    return $im;
}
