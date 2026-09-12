<?php

use App\Proofgen\Image;
use App\Services\CoreImageDaemonService;
use App\Services\ImageEnhancementService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Image as InterventionImage;
use Intervention\Image\ImageManager;

/*
|--------------------------------------------------------------------------
| Derivative output behavior (proofs / web / highres)
|--------------------------------------------------------------------------
|
| These tests exercise the real bytes on disk: dimensions, pixels, and JPEG
| quantization tables (which are a deterministic function of encoder quality).
| Enhancement is stubbed at the existing container boundary; every source and
| watermark fixture is generated in a per-test temp directory so nothing here
| touches the operator's images, fonts, or storage/watermarks assets.
|
*/

beforeEach(function () {
    $this->derivativeRoot = storage_path('app/derivative-output-test-'.uniqid());
    File::makeDirectory($this->derivativeRoot, 0755, true);

    config([
        'proofgen.fullsize_home_dir' => $this->derivativeRoot,
        'filesystems.disks.fullsize' => [
            'driver' => 'local',
            'root' => $this->derivativeRoot,
            'throw' => true,
        ],
        'proofgen.image_enhancement_enabled' => false,
        'proofgen.enhancement_apply_to_proofs' => false,
        'proofgen.enhancement_apply_to_web' => false,
        'proofgen.enhancement_apply_to_highres' => false,
        'proofgen.image_enhancement_method' => 'basic_auto_levels',
        'proofgen.watermark_proofs' => false,
        // Opaque white text, fully transparent background.
        'proofgen.watermark_foreground_opacity' => 0,
        'proofgen.watermark_background_opacity' => 127,
        'proofgen.thumbnails' => [
            // 4:3 targets so a 400x300 source lands on exact, assertable sizes.
            'small' => ['suffix' => '_thm', 'width' => 80, 'height' => 60, 'quality' => 60, 'font_size' => 10, 'bg_size' => 18],
            'large' => ['suffix' => '_std', 'width' => 200, 'height' => 150, 'quality' => 55, 'font_size' => 14, 'bg_size' => 30],
        ],
        'proofgen.web_images' => ['suffix' => '_web', 'width' => 320, 'height' => 240, 'quality' => 62],
        'proofgen.highres_images' => ['suffix' => '_highres', 'width' => 400, 'height' => 300, 'quality' => 68],
    ]);

    Storage::forgetDisk('fullsize');

    // Sandbox storage so the watermark asset can be created/removed/corrupted
    // without touching the repo asset. The real font path is kept absolute.
    $this->realStoragePath = app()->storagePath();
    $this->sandboxStoragePath = $this->derivativeRoot.'/storage';
    File::makeDirectory($this->sandboxStoragePath.'/watermarks', 0755, true);
    app()->useStoragePath($this->sandboxStoragePath);

    $this->watermarkFont = $this->realStoragePath.'/watermark_fonts/Georgia_Bold.ttf';
    config(['proofgen.watermark_font' => $this->watermarkFont]);

    proofgenDerivativeWriteWatermark($this->sandboxStoragePath.'/watermarks/web-image-watermark-2.png');

    // Synthetic mid-blue 4:3 source.
    $this->sourceRelative = 'SHOW1/121/IMG_0001.jpg';
    Storage::disk('fullsize')->put($this->sourceRelative, proofgenDerivativeJpeg(400, 300, [40, 90, 160]));
});

afterEach(function () {
    app()->useStoragePath($this->realStoragePath);

    if (File::exists($this->derivativeRoot)) {
        File::deleteDirectory($this->derivativeRoot);
    }

    Mockery::close();
});

it('builds small and large proofs from one enhanced source without upscaling', function () {
    config([
        'proofgen.image_enhancement_enabled' => true,
        'proofgen.enhancement_apply_to_proofs' => true,
    ]);
    proofgenDerivativeBindRedEnhancement();

    expect(Image::createThumbnails($this->sourceRelative, 'proofs/SHOW1/121'))->toBe('IMG_0001');

    $small = $this->derivativeRoot.'/proofs/SHOW1/121/IMG_0001_thm.jpg';
    $large = $this->derivativeRoot.'/proofs/SHOW1/121/IMG_0001_std.jpg';

    expect(is_file($small))->toBeTrue();
    expect(is_file($large))->toBeTrue();
    expect(getimagesize($small)[0])->toBe(80);
    expect(getimagesize($small)[1])->toBe(60);
    expect(getimagesize($large)[0])->toBe(200);
    expect(getimagesize($large)[1])->toBe(150);

    // Both derive from the red enhanced source; the blue original must not leak
    // into the large proof (the historical large-from-original bug).
    proofgenDerivativeAssertDominantRed($small);
    proofgenDerivativeAssertDominantRed($large);
});

it('does not upscale a proof source smaller than the target', function () {
    Storage::disk('fullsize')->put('SHOW1/121/IMG_small.jpg', proofgenDerivativeJpeg(50, 40, [10, 200, 10]));

    Image::createThumbnails('SHOW1/121/IMG_small.jpg', 'proofs/SHOW1/121');

    $small = $this->derivativeRoot.'/proofs/SHOW1/121/IMG_small_thm.jpg';
    $large = $this->derivativeRoot.'/proofs/SHOW1/121/IMG_small_std.jpg';

    expect(getimagesize($small)[0])->toBe(50);
    expect(getimagesize($small)[1])->toBe(40);
    expect(getimagesize($large)[0])->toBe(50);
    expect(getimagesize($large)[1])->toBe(40);
});

it('encodes un-watermarked proofs at the configured quality', function () {
    Image::createThumbnails($this->sourceRelative, 'proofs/SHOW1/121');

    $small = $this->derivativeRoot.'/proofs/SHOW1/121/IMG_0001_thm.jpg';
    $large = $this->derivativeRoot.'/proofs/SHOW1/121/IMG_0001_std.jpg';

    expect(proofgenDerivativeQuantTable($small))->toBe(proofgenDerivativeReferenceQuantTable(60));
    expect(proofgenDerivativeQuantTable($large))->toBe(proofgenDerivativeReferenceQuantTable(55));
});

it('second-encodes watermarked proofs at quality 95', function () {
    if (! is_file($this->watermarkFont)) {
        $this->markTestSkipped('Watermark font asset is not available in this environment.');
    }
    config(['proofgen.watermark_proofs' => true]);

    Image::createThumbnails($this->sourceRelative, 'proofs/SHOW1/121');

    $small = $this->derivativeRoot.'/proofs/SHOW1/121/IMG_0001_thm.jpg';
    $large = $this->derivativeRoot.'/proofs/SHOW1/121/IMG_0001_std.jpg';

    expect(proofgenDerivativeQuantTable($small))->toBe(proofgenDerivativeReferenceQuantTable(95));
    expect(proofgenDerivativeQuantTable($large))->toBe(proofgenDerivativeReferenceQuantTable(95));
});

it('watermarks landscape and portrait proof layouts', function () {
    if (! is_file($this->watermarkFont)) {
        $this->markTestSkipped('Watermark font asset is not available in this environment.');
    }
    config(['proofgen.watermark_proofs' => true]);

    Storage::disk('fullsize')->put('SHOW1/121/IMG_land.jpg', proofgenDerivativeJpeg(400, 300, [128, 128, 128]));
    Storage::disk('fullsize')->put('SHOW1/121/IMG_port.jpg', proofgenDerivativeJpeg(300, 400, [128, 128, 128]));

    Image::createThumbnails('SHOW1/121/IMG_land.jpg', 'proofs/SHOW1/121');
    Image::createThumbnails('SHOW1/121/IMG_port.jpg', 'proofs/SHOW1/121');

    $land = $this->derivativeRoot.'/proofs/SHOW1/121/IMG_land_std.jpg';
    $port = $this->derivativeRoot.'/proofs/SHOW1/121/IMG_port_std.jpg';

    expect(getimagesize($land)[0])->toBe(200);
    expect(getimagesize($land)[1])->toBe(150);
    expect(getimagesize($port)[0])->toBe(113);
    expect(getimagesize($port)[1])->toBe(150);

    // Opaque white watermark text on the dark proof.
    expect(proofgenDerivativeBrightPixelCount($land))->toBeGreaterThan(20);
    expect(proofgenDerivativeBrightPixelCount($port))->toBeGreaterThan(20);
});

it('refuses to write a web image when the watermark cannot be decoded', function () {
    Storage::disk('fullsize')->put('web_images/SHOW1/121/IMG_0001_web.jpg', 'prior-web-output');
    File::put($this->sandboxStoragePath.'/watermarks/web-image-watermark-2.png', 'not a png');

    expect(fn () => Image::createWebImage($this->sourceRelative, 'web_images/SHOW1/121'))
        ->toThrow(RuntimeException::class, 'could not be decoded');

    // A previously generated output must be left exactly as it was.
    expect(File::get($this->derivativeRoot.'/web_images/SHOW1/121/IMG_0001_web.jpg'))->toBe('prior-web-output');
});

it('does not create a highres image when the watermark is missing', function () {
    File::delete($this->sandboxStoragePath.'/watermarks/web-image-watermark-2.png');

    expect(fn () => Image::createHighresImage($this->sourceRelative, 'highres_images/SHOW1/121'))
        ->toThrow(RuntimeException::class, 'watermark');

    expect(is_file($this->derivativeRoot.'/highres_images/SHOW1/121/IMG_0001_highres.jpg'))->toBeFalse();
});

it('writes a watermarked web image once at the configured quality', function () {
    $output = Image::createWebImage($this->sourceRelative, 'web_images/SHOW1/121');

    expect($output)->toBe($this->derivativeRoot.'/web_images/SHOW1/121/IMG_0001_web.jpg');
    expect(is_file($output))->toBeTrue();
    expect(getimagesize($output)[0])->toBe(320);
    expect(getimagesize($output)[1])->toBe(240);
    expect(proofgenDerivativeQuantTable($output))->toBe(proofgenDerivativeReferenceQuantTable(62));

    // The opaque watermark shows up as pixels that differ from the solid source.
    expect(proofgenDerivativeDeviatingPixelCount($output, [40, 90, 160]))->toBeGreaterThan(50);
});

it('writes a watermarked highres image once at the configured quality', function () {
    $output = Image::createHighresImage($this->sourceRelative, 'highres_images/SHOW1/121');

    expect(is_file($output))->toBeTrue();
    expect(getimagesize($output)[0])->toBe(400);
    expect(getimagesize($output)[1])->toBe(300);
    expect(proofgenDerivativeQuantTable($output))->toBe(proofgenDerivativeReferenceQuantTable(68));
});

function proofgenDerivativeJpeg(int $width, int $height, array $rgb): string
{
    $gd = imagecreatetruecolor($width, $height);
    imagefill($gd, 0, 0, imagecolorallocate($gd, $rgb[0], $rgb[1], $rgb[2]));
    ob_start();
    imagejpeg($gd, null, 100);
    $bytes = ob_get_clean();
    imagedestroy($gd);

    return $bytes;
}

function proofgenDerivativeWriteWatermark(string $path): void
{
    $gd = imagecreatetruecolor(120, 30);
    imagesavealpha($gd, true);
    imagealphablending($gd, false);
    imagefill($gd, 0, 0, imagecolorallocatealpha($gd, 0, 0, 0, 127));
    imagealphablending($gd, true);
    imagefilledrectangle($gd, 0, 0, 119, 29, imagecolorallocatealpha($gd, 0, 0, 0, 0));
    imagepng($gd, $path);
    imagedestroy($gd);
}

function proofgenDerivativeBindRedEnhancement(): void
{
    $daemon = Mockery::mock(CoreImageDaemonService::class);
    $daemon->shouldReceive('isCoreImageAvailable')->andReturn(false);
    app()->instance(CoreImageDaemonService::class, $daemon);

    app()->instance(ImageEnhancementService::class, new ProofgenDerivativeRedEnhancement);
}

class ProofgenDerivativeRedEnhancement extends ImageEnhancementService
{
    public function enhance(string $imagePath, string $method, array $parameters = []): InterventionImage
    {
        $size = @getimagesize($imagePath);
        $width = is_array($size) ? $size[0] : 100;
        $height = is_array($size) ? $size[1] : 100;

        $gd = imagecreatetruecolor($width, $height);
        imagefill($gd, 0, 0, imagecolorallocate($gd, 255, 0, 0));
        ob_start();
        imagejpeg($gd, null, 100);
        $bytes = ob_get_clean();
        imagedestroy($gd);

        return (new ImageManager(GdDriver::class))->decodeBinary($bytes);
    }
}

function proofgenDerivativeAssertDominantRed(string $path): void
{
    $gd = imagecreatefromjpeg($path);
    $width = imagesx($gd);
    $height = imagesy($gd);

    foreach ([[(int) ($width / 2), (int) ($height / 2)], [5, 5], [$width - 5, $height - 5]] as [$x, $y]) {
        $color = imagecolorat($gd, $x, $y);
        $r = ($color >> 16) & 0xFF;
        $g = ($color >> 8) & 0xFF;
        $b = $color & 0xFF;

        expect($r)->toBeGreaterThan(180);
        expect($g)->toBeLessThan(90);
        expect($b)->toBeLessThan(90);
    }

    imagedestroy($gd);
}

function proofgenDerivativeBrightPixelCount(string $path): int
{
    $gd = imagecreatefromjpeg($path);
    $width = imagesx($gd);
    $height = imagesy($gd);
    $count = 0;

    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $color = imagecolorat($gd, $x, $y);
            if ((($color >> 16) & 0xFF) > 200 && (($color >> 8) & 0xFF) > 200 && ($color & 0xFF) > 200) {
                $count++;
            }
        }
    }

    imagedestroy($gd);

    return $count;
}

function proofgenDerivativeDeviatingPixelCount(string $path, array $rgb): int
{
    $gd = imagecreatefromjpeg($path);
    $width = imagesx($gd);
    $height = imagesy($gd);
    $count = 0;

    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $color = imagecolorat($gd, $x, $y);
            $r = ($color >> 16) & 0xFF;
            $g = ($color >> 8) & 0xFF;
            $b = $color & 0xFF;

            if (abs($r - $rgb[0]) + abs($g - $rgb[1]) + abs($b - $rgb[2]) > 150) {
                $count++;
            }
        }
    }

    imagedestroy($gd);

    return $count;
}

/**
 * Read the first JPEG quantization table (DQT) from a file.
 *
 * @return int[]
 */
function proofgenDerivativeQuantTable(string $path): array
{
    $data = file_get_contents($path);
    $length = strlen($data);
    $offset = 2;

    while ($offset + 4 <= $length) {
        if (ord($data[$offset]) !== 0xFF) {
            $offset++;

            continue;
        }

        $marker = ord($data[$offset + 1]);
        if ($marker === 0xFF) {
            $offset++;

            continue;
        }

        $segmentLength = (ord($data[$offset + 2]) << 8) | ord($data[$offset + 3]);

        if ($marker === 0xDB) {
            $tableStart = $offset + 4;
            $precision = ord($data[$tableStart]) >> 4;

            if ($precision === 1) {
                $table = [];
                for ($i = 0; $i < 64; $i++) {
                    $table[] = (ord($data[$tableStart + 1 + ($i * 2)]) << 8) | ord($data[$tableStart + 2 + ($i * 2)]);
                }

                return $table;
            }

            return array_values(unpack('C*', substr($data, $tableStart + 1, 64)));
        }

        if ($marker === 0xDA) {
            break;
        }

        $offset += 2 + $segmentLength;
    }

    throw new RuntimeException('No JPEG quantization table found in '.$path);
}

/**
 * Build the quantization table GD/libjpeg emits for a given quality.
 *
 * @return int[]
 */
function proofgenDerivativeReferenceQuantTable(int $quality): array
{
    $base = tempnam(sys_get_temp_dir(), 'proofgen_q');
    $tmp = $base.'.jpg';
    @unlink($base);

    $gd = imagecreatetruecolor(16, 16);
    imagefill($gd, 0, 0, imagecolorallocate($gd, 128, 128, 128));
    imagejpeg($gd, $tmp, $quality);
    imagedestroy($gd);

    $table = proofgenDerivativeQuantTable($tmp);
    @unlink($tmp);

    return $table;
}

it('preserves EXIF orientation through the in-memory GD enhancement fallback', function () {
    $jpeg = proofgenDerivativeJpeg(400, 300, [100, 120, 140]);
    $tiff = 'II'.pack('vV', 42, 8).pack('v', 1).pack('vvVv', 0x0112, 3, 1, 6)."\0\0".pack('V', 0);
    $exif = "Exif\0\0".$tiff;
    $jpeg = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
    $path = $this->derivativeRoot.'/rotated.jpg';
    file_put_contents($path, $jpeg);

    $result = (new ImageEnhancementService)->enhance($path, 'adjustable_auto_levels');
    expect($result->width())->toBe(300)->and($result->height())->toBe(400);
});
