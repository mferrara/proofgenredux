<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Intervention\Image\Image;

class PhotoMetadata extends Model
{
    protected $table = 'photo_metadata';

    protected $primaryKey = 'photo_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'photo_id' => 'string',
        'file_size' => 'integer',
        'height' => 'integer',
        'width' => 'integer',
        'exif_timestamp' => 'datetime',
    ];

    public function photo(): BelongsTo
    {
        return $this->belongsTo(Photo::class, 'photo_id', 'id');
    }

    public function getRouteKeyName(): string
    {
        return 'photo_id';
    }

    public function fillFromExifDataArray(array $exif_data)
    {
        $orientation = null;
        $orientation_value = null;
        $camera_model = null;
        $camera_make = null;
        $artist = null;
        if (isset($exif_data['IFD0'])) {
            $ifd0 = $exif_data['IFD0'];
            $camera_model = $ifd0['Model'] ?? null;
            $camera_make = $ifd0['Make'] ?? null;
            $artist = $ifd0['Artist'] ?? null;
            if (! $artist) {
                $artist = $ifd0['Copyright'] ?? null;
            }
            $orientation_value = $ifd0['Orientation'] ?? null;
        }

        $shutter_speed = null;
        $fnumber = null;
        $iso_speed_ratings = null;
        $exif_timestamp = null;
        $exposure_bias_value = null;
        $max_aperture_value = null;
        $focal_length = null;
        if (isset($exif_data['EXIF'])) {
            $exif = $exif_data['EXIF'];
            // ExposureTime is provided as a value similar to 10/3200 so we'll need to simplify the fraction
            $shutter_speed = $exif['ExposureTime'] ?? null;
            if ($shutter_speed) {
                $shutter_speed = explode('/', $shutter_speed);
                if (count($shutter_speed) === 2) {
                    // Nikon and canon give different values, the nikon provided 10/3200 for 1/320th of a second
                    // but the canon gave 1/80 for 1/80th of a second so we'll need simplify the fraction where the
                    // numerator is 10
                    if ((int) $shutter_speed[0] === 10) {
                        $shutter_speed = ($shutter_speed[0] / 10).'/'.($shutter_speed[1] / 10);
                    } else {
                        $shutter_speed = ($shutter_speed[0].'/'.$shutter_speed[1]);
                    }
                }
            }
            // FNumber is provided as a value similar to 56/10 so we'll need to simplify the fraction
            $fnumber = $exif['FNumber'] ?? null;
            if ($fnumber) {
                $fnumber = explode('/', $fnumber);
                if (count($fnumber) === 2) {
                    $fnumber = ($fnumber[0] / $fnumber[1]);
                }
            }
            $iso_speed_ratings = $exif['ISOSpeedRatings'] ?? null;
            $exif_timestamp = $exif['DateTimeOriginal'] ?? null;
            $exposure_bias_value = $exif['ExposureBiasValue'] ?? null;
            // MaxApertureValue is provided as a value similar to 40/10 so we'll need to simplify the fraction
            $max_aperture_value = $exif['MaxApertureValue'] ?? null;
            if ($max_aperture_value) {
                $max_aperture_value = explode('/', $max_aperture_value);
                if (count($max_aperture_value) === 2) {
                    $max_aperture_value = ($max_aperture_value[0] / $max_aperture_value[1]);
                }
            }
            // FocalLength is provided as a value similar to 1100/10 so we'll need to simplify the fraction
            $focal_length = $exif['FocalLength'] ?? null;
            if ($focal_length) {
                $focal_length = explode('/', $focal_length);
                if (count($focal_length) === 2) {
                    $focal_length = ($focal_length[0] / $focal_length[1]);
                }
            }
        }

        $height = null;
        $width = null;
        if (isset($exif_data['COMPUTED'])) {
            $computed = $exif_data['COMPUTED'];
            $height = $computed['Height'] ?? null;
            $width = $computed['Width'] ?? null;
        }

        $this->shutter_speed = $shutter_speed;
        $this->aperture = $fnumber;
        $this->iso = $iso_speed_ratings;
        $this->exposure_bias = $exposure_bias_value;
        $this->max_aperture = $max_aperture_value;
        $this->focal_length = $focal_length;
        $this->camera_model = $camera_model;
        $this->camera_make = $camera_make;
        $this->artist = $artist;
        $this->exif_timestamp = $exif_timestamp;
        $this->height = $height;
        $this->width = $width;

        // When the cameras are turned to the side their sensor is still _technically_ shooting
        // an image with a height and width in landscape as far as the aspect ratio is concerned
        // but in reality, it's implied that the image is in portrait mode - this is indicated by this
        // orientation value from the camera, where 6 is the camera turned 90 degrees to the right
        // and 8 is the camera turned 270 degrees to the right (or, 90 degrees to the left)
        if ($orientation_value === 6 || $orientation_value === 8) {
            // Swap width and height for aspect ratio calculation
            $this->width = $height;
            $this->height = $width;
        }

        if (($this->width === $this->height) && ($this->width !== null && $this->height !== null)) {
            $orientation = 'sq';
            \Log::debug('somehow this is sq, make it make sense: '.$this->width.'x'.$this->height);
        } elseif ($this->width > $this->height) {
            $orientation = 'la';
        } elseif ($this->width < $this->height) {
            $orientation = 'po';
        }
        $this->orientation = $orientation;
        $this->aspect_ratio = '';
        if ($this->width && $this->height) {
            // First calculate the exact ratio
            $a = $this->width;
            $b = $this->height;
            while ($b != 0) {
                $temp = $b;
                $b = $a % $b;
                $a = $temp;
            }
            $divisor = $a;

            $w = $this->width / $divisor;
            $h = $this->height / $divisor;

            // Calculate the decimal ratio
            $ratio = $this->width / $this->height;

            // Map to common aspect ratios
            if (abs($ratio - 1) < 0.01) {
                $this->aspect_ratio = '1:1'; // Square
            } elseif (abs($ratio - 1.5) < 0.01) {
                $this->aspect_ratio = '3:2'; // Standard DSLR
            } elseif (abs($ratio - 1.33) < 0.01) {
                $this->aspect_ratio = '4:3'; // Standard monitor
            } elseif (abs($ratio - 1.78) < 0.01) {
                $this->aspect_ratio = '16:9'; // Widescreen
            } elseif (abs($ratio - 1.25) < 0.01) {
                $this->aspect_ratio = '5:4'; // Medium format
            } else {
                // Fall back to the precise calculation if it doesn't match common ratios
                $this->aspect_ratio = $w.':'.$h;
            }

            // Calculate megapixels
            $this->megapixels = ($this->width * $this->height) / 1000000;

            // Optional: Round to 1 decimal place for display
            $this->megapixels = round($this->megapixels, 1);
        }
    }
}
