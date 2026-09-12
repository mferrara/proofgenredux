<?php

use App\Livewire\ConfigComponent;
use App\Models\Configuration;
use App\Services\CoreImageDaemonService;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

beforeEach(function () {
    $this->previewRoot = storage_path('app/settings-preview-test-'.uniqid());
    File::makeDirectory($this->previewRoot, 0755, true);
    $this->sourceImage = $this->previewRoot.'/sample.jpg';
    $image = imagecreatetruecolor(120, 80);
    imagefill($image, 0, 0, imagecolorallocate($image, 40, 100, 160));
    imagejpeg($image, $this->sourceImage);

    Configuration::setConfig('watermark_proofs', 'true', 'boolean', 'watermarks');
    config([
        'proofgen.watermark_font' => $this->previewRoot.'/missing-font.ttf',
        'proofgen.thumbnails.small.width' => 60,
        'proofgen.thumbnails.small.height' => 40,
        'proofgen.thumbnails.small.quality' => 61,
        'proofgen.thumbnails.small.font_size' => 8,
        'proofgen.thumbnails.small.bg_size' => 12,
        'proofgen.thumbnails.large.width' => 120,
        'proofgen.thumbnails.large.height' => 80,
        'proofgen.thumbnails.large.quality' => 57,
        'proofgen.thumbnails.large.font_size' => 8,
        'proofgen.thumbnails.large.bg_size' => 12,
        'proofgen.web_images.width' => 120,
        'proofgen.web_images.height' => 80,
        'proofgen.web_images.quality' => 44,
        'proofgen.highres_images.width' => 120,
        'proofgen.highres_images.height' => 80,
        'proofgen.highres_images.quality' => 46,
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->previewRoot);
});

it('shows a font error and recovers with a real watermarked preview on retry', function (string $size, string $property, int $width) {
    $component = Livewire::test(SettingsPreviewGenerationFixture::class)
        ->set('sampleImagePath', $this->sourceImage)
        ->call('updateActiveTab', $size)
        ->assertSet('previewLoading', false)
        ->assertSet($property, null)
        ->assertSee('Preview could not be generated')
        ->assertSee('Update Watermark Font in Settings.')
        ->assertSee('Retry preview');

    expect($component->get('previewErrors')[$size])->toContain('missing-font.ttf');

    config(['proofgen.watermark_font' => storage_path('watermark_fonts/Georgia_Bold.ttf')]);
    $component->call('generateThumbnailPreviews')
        ->assertSet('previewErrors', [])
        ->assertSet('previewLoading', false)
        ->assertDontSee('Preview could not be generated');

    $preview = storage_path('app/temp/thumbnail-previews/'.basename($component->get($property)));
    expect(is_file($preview))->toBeTrue();
    $info = getimagesize($preview);
    expect($info[0])->toBe($width)->and($info[2])->toBe(IMAGETYPE_JPEG);
})->with([
    'large' => ['large', 'largeThumbnailPreview', 120],
    'small' => ['small', 'smallThumbnailPreview', 60],
]);

it('keeps a thumbnail failure separate from the working web preview', function () {
    $component = Livewire::test(SettingsPreviewGenerationFixture::class)
        ->set('sampleImagePath', $this->sourceImage)
        ->call('updateActiveTab', 'large')
        ->call('updateActiveTab', 'web')
        ->assertSet('previewLoading', false);

    expect($component->get('previewErrors'))->toHaveKey('large')->not->toHaveKey('web');
    expect($component->get('webImagePreview'))->not->toBeNull();
});

it('encodes the un-watermarked proof preview at the configured quality', function () {
    $component = Livewire::test(SettingsPreviewGenerationFixture::class)
        ->set('sampleImagePath', $this->sourceImage)
        ->set('previewWatermarkEnabled', false)
        ->call('updateActiveTab', 'large')
        ->assertSet('previewErrors', []);

    $preview = storage_path('app/temp/thumbnail-previews/'.basename($component->get('largeThumbnailPreview')));
    expect(is_file($preview))->toBeTrue();
    expect(proofgenPreviewQuantTable($preview))->toBe(proofgenPreviewReferenceQuantTable(57));
});

it('second-encodes the watermarked proof preview at quality 95', function () {
    $font = storage_path('watermark_fonts/Georgia_Bold.ttf');
    if (! is_file($font)) {
        $this->markTestSkipped('Watermark font asset is not available in this environment.');
    }
    config(['proofgen.watermark_font' => $font]);

    $component = Livewire::test(SettingsPreviewGenerationFixture::class)
        ->set('sampleImagePath', $this->sourceImage)
        ->call('updateActiveTab', 'large')
        ->assertSet('previewErrors', []);

    $preview = storage_path('app/temp/thumbnail-previews/'.basename($component->get('largeThumbnailPreview')));
    expect(is_file($preview))->toBeTrue();
    expect(proofgenPreviewQuantTable($preview))->toBe(proofgenPreviewReferenceQuantTable(95));
});

it('encodes the paid web preview at the configured quality', function () {
    if (! is_file(storage_path('watermarks/web-image-watermark-2.png'))) {
        $this->markTestSkipped('Web watermark asset is not available in this environment.');
    }

    $component = Livewire::test(SettingsPreviewGenerationFixture::class)
        ->set('sampleImagePath', $this->sourceImage)
        ->call('updateActiveTab', 'web')
        ->assertSet('previewErrors', []);

    $preview = storage_path('app/temp/thumbnail-previews/'.basename($component->get('webImagePreview')));
    expect(is_file($preview))->toBeTrue();
    expect(proofgenPreviewQuantTable($preview))->toBe(proofgenPreviewReferenceQuantTable(44));
});

class SettingsPreviewGenerationFixture extends ConfigComponent
{
    public function mount(): void
    {
        // Exercise the real renderer and image generation without process/update checks.
        $setting = Configuration::where('key', 'watermark_proofs')->firstOrFail();
        $this->configurationsByCategory = ['watermarks' => collect([$setting]), 'thumbnails' => collect()];
        $this->configValues = [$setting->id => true];
    }
}

/**
 * Read the first JPEG quantization table (DQT) from a file.
 *
 * @return int[]
 */
function proofgenPreviewQuantTable(string $path): array
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
function proofgenPreviewReferenceQuantTable(int $quality): array
{
    $base = tempnam(sys_get_temp_dir(), 'proofgen_preview_q');
    $tmp = $base.'.jpg';
    @unlink($base);

    $gd = imagecreatetruecolor(16, 16);
    imagefill($gd, 0, 0, imagecolorallocate($gd, 128, 128, 128));
    imagejpeg($gd, $tmp, $quality);
    imagedestroy($gd);

    $table = proofgenPreviewQuantTable($tmp);
    @unlink($tmp);

    return $table;
}

it('reports unsupported enhancement without an enhanced comparison or a broken preview', function () {
    foreach (['image_enhancement_enabled' => true, 'enhancement_apply_to_proofs' => true, 'image_enhancement_method' => 'advanced_tone_mapping'] as $key => $value) {
        Configuration::setConfig($key, is_bool($value) ? 'true' : $value, is_bool($value) ? 'boolean' : 'string', 'enhancement');
    }
    $daemon = Mockery::mock(CoreImageDaemonService::class);
    $daemon->shouldReceive('isCoreImageAvailable')->andReturnFalse();
    app()->instance(CoreImageDaemonService::class, $daemon);
    config(['proofgen.tone_mapping_shadow_amount' => 50]);

    Livewire::test(SettingsEnhancementFailureFixture::class)
        ->set('sampleImagePath', $this->sourceImage)
        ->set('previewWatermarkEnabled', false)
        ->call('updateActiveTab', 'large')
        ->assertSet('previewErrors', [])
        ->assertSet('largeThumbnailEnhancementInfo.enabled', false)
        ->assertSet('largeThumbnailPreviewUnenhanced', null)
        ->assertSee('Enhancement not applied:')
        ->assertSee('Core Image')
        ->assertDontSee('Enhanced:');
});

class SettingsEnhancementFailureFixture extends SettingsPreviewGenerationFixture
{
    public function mount(): void
    {
        parent::mount();
        $settings = Configuration::whereIn('key', ['image_enhancement_enabled', 'enhancement_apply_to_proofs', 'image_enhancement_method'])->get();
        $this->configurationsByCategory['enhancement'] = $settings;
        foreach ($settings as $setting) {
            $this->configValues[$setting->id] = $setting->key === 'image_enhancement_method' ? 'advanced_tone_mapping' : true;
        }
    }
}
