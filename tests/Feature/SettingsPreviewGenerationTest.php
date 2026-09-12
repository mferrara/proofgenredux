<?php

use App\Livewire\ConfigComponent;
use App\Models\Configuration;
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
        'proofgen.thumbnails.small.font_size' => 8,
        'proofgen.thumbnails.small.bg_size' => 12,
        'proofgen.thumbnails.large.width' => 120,
        'proofgen.thumbnails.large.height' => 80,
        'proofgen.thumbnails.large.font_size' => 8,
        'proofgen.thumbnails.large.bg_size' => 12,
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
