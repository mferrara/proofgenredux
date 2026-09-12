<?php

use App\Livewire\ConfigComponent;
use Livewire\Livewire;

it('keeps the requested watermark state and regenerates once per update', function () {
    Livewire::test(PreviewWatermarkToggleFixture::class)
        ->assertSet('previewWatermarkEnabled', true)
        ->set('previewWatermarkEnabled', false)
        ->assertSet('previewWatermarkEnabled', false)
        ->assertSet('previewCalls', 1)
        ->set('previewWatermarkEnabled', true)
        ->assertSet('previewWatermarkEnabled', true)
        ->assertSet('previewCalls', 2);
});

class PreviewWatermarkToggleFixture extends ConfigComponent
{
    public int $previewCalls = 0;

    public function mount(): void
    {
        // No sample images, Swift checks, updater or local process discovery.
    }

    public function generateThumbnailPreviews(): void
    {
        $this->previewCalls++;
    }

    public function render()
    {
        return '<div></div>';
    }
}
