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
        'gps_latitude' => 'decimal:7',
        'gps_longitude' => 'decimal:7',
        'gps_altitude' => 'decimal:2',
        'focal_length_35mm' => 'decimal:1',
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

        $forensic = self::extractForensicFields($exif_data);

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

        $this->subsec_time_original = $forensic['subsec_time_original'];
        $this->lens_make = $forensic['lens_make'];
        $this->lens_model = $forensic['lens_model'];
        $this->body_serial_number = $forensic['body_serial_number'];
        $this->lens_serial_number = $forensic['lens_serial_number'];
        $this->image_unique_id = $forensic['image_unique_id'];
        $this->gps_latitude = $forensic['gps_latitude'];
        $this->gps_longitude = $forensic['gps_longitude'];
        $this->gps_altitude = $forensic['gps_altitude'];
        $this->software = $forensic['software'];
        $this->color_space = $forensic['color_space'];
        $this->white_balance = $forensic['white_balance'];
        $this->exposure_program = $forensic['exposure_program'];
        $this->metering_mode = $forensic['metering_mode'];
        $this->flash = $forensic['flash'];
        $this->focal_length_35mm = $forensic['focal_length_35mm'];

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

    /**
     * Pull forensic identifiers + shooting context from a parsed EXIF array without
     * persisting anything. Used by PhotoImportIdentityResolver to attach a fingerprint
     * to the import plan and by audit findings to surface near-duplicate context.
     */
    public static function extractForensicFields(array $exif_data): array
    {
        $ifd0 = $exif_data['IFD0'] ?? [];
        $exif = $exif_data['EXIF'] ?? [];
        $gps = $exif_data['GPS'] ?? [];

        return [
            'subsec_time_original' => self::stringOrNull($exif['SubSecTimeOriginal'] ?? $exif['SubSecTime'] ?? null),
            'lens_make' => self::stringOrNull($exif['LensMake'] ?? $ifd0['LensMake'] ?? null),
            'lens_model' => self::stringOrNull($exif['LensModel'] ?? $ifd0['LensModel'] ?? null),
            'body_serial_number' => self::stringOrNull(
                $exif['BodySerialNumber']
                ?? $ifd0['BodySerialNumber']
                ?? $exif['SerialNumber']
                ?? $ifd0['SerialNumber']
                ?? null
            ),
            'lens_serial_number' => self::stringOrNull($exif['LensSerialNumber'] ?? $ifd0['LensSerialNumber'] ?? null),
            'image_unique_id' => self::stringOrNull($exif['ImageUniqueID'] ?? $ifd0['ImageUniqueID'] ?? null),
            'gps_latitude' => self::gpsCoordinate($gps['GPSLatitude'] ?? null, $gps['GPSLatitudeRef'] ?? null),
            'gps_longitude' => self::gpsCoordinate($gps['GPSLongitude'] ?? null, $gps['GPSLongitudeRef'] ?? null),
            'gps_altitude' => self::gpsAltitude($gps['GPSAltitude'] ?? null, $gps['GPSAltitudeRef'] ?? null),
            'software' => self::stringOrNull($ifd0['Software'] ?? null),
            'color_space' => self::colorSpaceName($exif['ColorSpace'] ?? null),
            'white_balance' => self::whiteBalanceName($exif['WhiteBalance'] ?? null),
            'exposure_program' => self::exposureProgramName($exif['ExposureProgram'] ?? null),
            'metering_mode' => self::meteringModeName($exif['MeteringMode'] ?? null),
            'flash' => self::flashName($exif['Flash'] ?? null),
            'focal_length_35mm' => self::rationalToFloat($exif['FocalLengthIn35mmFilm'] ?? null),
        ];
    }

    /**
     * Read the source file's EXIF and return the forensic payload, or an empty array
     * when the file isn't EXIF-bearing. Suitable for one-shot use during import.
     */
    public static function fingerprintFromFile(string $absolutePath): array
    {
        if (! is_file($absolutePath)) {
            return [];
        }

        $exif = @exif_read_data($absolutePath, 'EXIF', true);
        if ($exif === false) {
            return [];
        }

        $forensic = self::extractForensicFields($exif);
        $forensic['exif_timestamp'] = $exif['EXIF']['DateTimeOriginal']
            ?? $exif['IFD0']['DateTime']
            ?? null;
        $forensic['camera_make'] = self::stringOrNull($exif['IFD0']['Make'] ?? null);
        $forensic['camera_model'] = self::stringOrNull($exif['IFD0']['Model'] ?? null);

        return $forensic;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $str = trim((string) $value);

        return $str === '' ? null : $str;
    }

    private static function rationalToFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        $parts = explode('/', (string) $value);
        if (count($parts) === 2 && (float) $parts[1] !== 0.0) {
            return (float) $parts[0] / (float) $parts[1];
        }

        return null;
    }

    /**
     * EXIF GPS arrays come as [degrees, minutes, seconds] each as rational strings.
     * Returns signed decimal degrees (south/west = negative).
     */
    private static function gpsCoordinate(mixed $components, ?string $ref): ?float
    {
        if (! is_array($components) || count($components) !== 3) {
            return null;
        }
        $deg = self::rationalToFloat($components[0]);
        $min = self::rationalToFloat($components[1]);
        $sec = self::rationalToFloat($components[2]);
        if ($deg === null || $min === null || $sec === null) {
            return null;
        }
        $decimal = $deg + ($min / 60) + ($sec / 3600);
        if ($ref === 'S' || $ref === 'W') {
            $decimal = -$decimal;
        }

        return round($decimal, 7);
    }

    private static function gpsAltitude(mixed $altitude, mixed $ref): ?float
    {
        $value = self::rationalToFloat($altitude);
        if ($value === null) {
            return null;
        }
        // Ref 1 (or "\x01") = below sea level per EXIF spec.
        if ($ref === 1 || $ref === "\x01" || $ref === '1') {
            $value = -$value;
        }

        return round($value, 2);
    }

    private static function colorSpaceName(mixed $value): ?string
    {
        return match ((int) $value) {
            1 => 'sRGB',
            2 => 'Adobe RGB',
            65535 => 'Uncalibrated',
            default => null,
        };
    }

    private static function whiteBalanceName(mixed $value): ?string
    {
        return match ((int) $value) {
            0 => 'Auto',
            1 => 'Manual',
            default => null,
        };
    }

    private static function exposureProgramName(mixed $value): ?string
    {
        return match ((int) $value) {
            1 => 'Manual',
            2 => 'Program AE',
            3 => 'Aperture Priority',
            4 => 'Shutter Priority',
            5 => 'Creative',
            6 => 'Action',
            7 => 'Portrait',
            8 => 'Landscape',
            9 => 'Bulb',
            default => null,
        };
    }

    private static function meteringModeName(mixed $value): ?string
    {
        return match ((int) $value) {
            1 => 'Average',
            2 => 'Center-weighted',
            3 => 'Spot',
            4 => 'Multi-spot',
            5 => 'Multi-segment',
            6 => 'Partial',
            255 => 'Other',
            default => null,
        };
    }

    private static function flashName(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $int = (int) $value;
        $fired = ($int & 0x01) === 0x01;

        return $fired ? 'Fired' : 'Not fired';
    }
}
