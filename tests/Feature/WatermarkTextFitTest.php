<?php

use App\Proofgen\Image;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config([
        'proofgen.watermark_font' => storage_path('watermark_fonts/Georgia_Bold.ttf'),
        'proofgen.watermark_foreground_opacity' => 0,
        'proofgen.watermark_background_opacity' => 0,
        'proofgen.thumbnails.small.font_size' => 12,
        'proofgen.thumbnails.small.bg_size' => 22,
        'proofgen.thumbnails.large.font_size' => 18,
        'proofgen.thumbnails.large.bg_size' => 30,
    ]);
});

function watermarkInkBounds(GdImage $image): array
{
    $xs = $ys = [];
    for ($y = 0; $y < imagesy($image); $y++) {
        for ($x = 0; $x < imagesx($image); $x++) {
            $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));
            if ($color['red'] > 180 && $color['green'] > 180 && $color['blue'] > 180) {
                $xs[] = $x;
                $ys[] = $y;
            }
        }
    }
    expect($xs)->not->toBeEmpty();

    return [min($xs), min($ys), max($xs), max($ys), count($xs)];
}

it('fits complete long labels inside both watermark canvases', function (string $size, int $width) {
    $text = 'VERY_LONG_SHOW_NAME_00000001';
    $image = $size === 'small'
        ? Image::watermarkSmallProof($text, $width)
        : Image::watermarkLargeProof('Proof# '.$text.' - Illegal to use - Ferrara Photography', $width);
    [$left, $top, $right, $bottom] = watermarkInkBounds($image);
    expect(imagesx($image))->toBeLessThanOrEqual($width)
        ->and($left)->toBeGreaterThanOrEqual(2)
        ->and($right)->toBeLessThan($width - 2)
        ->and($top)->toBeGreaterThanOrEqual(2)
        ->and($bottom)->toBeLessThan(imagesy($image) - 2);
})->with([
    'small portrait' => ['small', 180],
    'small landscape' => ['small', 280],
    'large portrait' => ['large', 666],
    'large landscape' => ['large', 1000],
]);

it('retains natural small-label width and configured text size when it already fits', function () {
    $natural = Image::watermarkSmallProof('22BUCK_00021');
    $bounded = Image::watermarkSmallProof('22BUCK_00021', 280);
    expect(imagesx($bounded))->toBe(imagesx($natural))->toBeLessThan(280);
    expect(watermarkInkBounds($bounded))->toBe(watermarkInkBounds($natural));
});

it('passes the actual small proof width into watermark fitting in production', function (int $width, int $height) {
    Storage::fake('fullsize');
    config([
        'proofgen.fullsize_home_dir' => Storage::disk('fullsize')->path(''),
        'proofgen.image_enhancement_enabled' => false,
        'proofgen.watermark_proofs' => true,
        'proofgen.thumbnails.small.width' => 300,
        'proofgen.thumbnails.small.height' => 300,
        'proofgen.thumbnails.small.quality' => 50,
        'proofgen.thumbnails.small.suffix' => '_thm',
        'proofgen.thumbnails.large.width' => 1000,
        'proofgen.thumbnails.large.height' => 1000,
        'proofgen.thumbnails.large.quality' => 60,
        'proofgen.thumbnails.large.suffix' => '_std',
    ]);
    $label = 'VERY_LONG_SHOW_NAME_00000001';
    $source = Storage::disk('fullsize')->path($label.'.jpg');
    $gd = imagecreatetruecolor($width, $height);
    imagefill($gd, 0, 0, imagecolorallocate($gd, 90, 90, 90));
    imagejpeg($gd, $source);
    unset($gd);
    $limit = ini_get('memory_limit');
    try {
        Image::createThumbnails($label.'.jpg', 'proofs');
    } finally {
        ini_set('memory_limit', $limit);
    }
    $small = imagecreatefromjpeg(Storage::disk('fullsize')->path('proofs/'.$label.'_thm.jpg'));
    [$left, , $right] = watermarkInkBounds($small);
    expect($left)->toBeGreaterThanOrEqual(12)->and($right)->toBeLessThan(imagesx($small) - 11);
})->with(['portrait' => [800, 1200], 'landscape' => [1200, 800]]);
