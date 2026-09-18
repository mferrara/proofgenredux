<?php

use App\Proofgen\Image;

use function App\Proofgen\imagettfJustifytext;

/*
 * Proof watermark text must not grow a dark outline as the background is made
 * more transparent. The old palette canvas let GD anti-alias edges with
 * near-black pixels at almost the text's opacity.
 */

beforeEach(function () {
    config(['proofgen.watermark_font' => storage_path('watermark_fonts/Georgia_Bold.ttf')]);
    class_exists(Image::class); // the helper function lives in that file
});

function watermarkPixels(GdImage $image): array
{
    $pixels = [];
    for ($y = 0; $y < imagesy($image); $y++) {
        for ($x = 0; $x < imagesx($image); $x++) {
            $pixels[] = imagecolorsforindex($image, imagecolorat($image, $x, $y));
        }
    }

    return $pixels;
}

it('never makes an edge pixel both darker than the text and much more opaque than the background', function (int $backgroundAlpha) {
    $image = imagettfJustifytext('Proof# 26AAC_00001', '', 2, 600, 42, 0, 0, 22, [255, 255, 255, 45], [0, 0, 0, $backgroundAlpha]);
    $pixels = watermarkPixels($image);

    expect(imageistruecolor($image))->toBeTrue();

    // The signature of the old defect: hundreds of near-black pixels as opaque as the text.
    $outline = array_filter($pixels, fn (array $c) => $c['red'] < 64 && $c['alpha'] < $backgroundAlpha - 20);
    expect(count($outline))->toBe(0);

    // A smooth ramp, not a handful of palette entries.
    expect(count(array_unique(array_map(fn (array $c) => $c['red'].':'.$c['alpha'], $pixels))))->toBeGreaterThan(40);
})->with([85, 105, 120]);

it('keeps the configured text and background colours exactly where there is no edge', function () {
    $image = imagettfJustifytext('Proof# 26AAC_00001', '', 2, 600, 42, 0, 0, 22, [255, 255, 255, 45], [0, 0, 0, 105]);
    $pixels = watermarkPixels($image);

    // Text stays as bright as before the fix: full-coverage pixels are the text colour itself.
    expect(array_filter($pixels, fn (array $c) => $c['red'] === 255 && $c['alpha'] === 45))->not->toBeEmpty()
        ->and(array_filter($pixels, fn (array $c) => $c['red'] === 0 && $c['alpha'] === 105))->not->toBeEmpty();

    // Opacity only ever moves between the two configured values.
    expect(min(array_column($pixels, 'alpha')))->toBe(45)
        ->and(max(array_column($pixels, 'alpha')))->toBe(105);
});
