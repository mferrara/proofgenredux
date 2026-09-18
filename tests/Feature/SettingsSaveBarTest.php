<?php

use App\Livewire\ConfigComponent;
use App\Models\Configuration;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * The Settings page polls worker status every few seconds. Any such request
 * also carries typed values to the server, after which Livewire stops calling
 * the fields dirty. The save bar must stay up until the values are saved.
 */

beforeEach(function () {
    // No previews of operator photos.
    File::partialMock()->shouldReceive('exists')
        ->with(storage_path('sample_images'))->andReturnFalse();
    Storage::fake('fullsize');
});

afterEach(function () {
    Mockery::close();
});

it('keeps the save bar up through a background poll until the change is saved or discarded', function () {
    $row = Configuration::where('key', 'sftp.web_images_path')->firstOrFail();

    $page = Livewire::test(ConfigComponent::class)
        ->assertViewHas('hasUnsavedChanges', false)
        ->set('configValues.'.$row->id, '/mnt/photo-storage/web_images')
        ->assertViewHas('hasUnsavedChanges', true)
        // The worker-status poll that used to hide the bar.
        ->call('updateHorizonStatus')
        ->assertViewHas('hasUnsavedChanges', true);

    $page->call('cancel')->assertViewHas('hasUnsavedChanges', false);

    expect($row->fresh()->value)->not->toBe('/mnt/photo-storage/web_images');
});

it('lowers the save bar once the change is saved', function () {
    // The test environment blanks the image size/quality settings, which would
    // fail Save's validation for reasons unrelated to the save bar.
    Configuration::where('type', 'integer')
        ->get()
        ->filter(fn (Configuration $config) => (int) $config->value < 10)
        ->each(fn (Configuration $config) => $config->update(['value' => '90']));

    $row = Configuration::where('key', 'sftp.web_images_path')->firstOrFail();

    Livewire::test(ConfigComponent::class)
        ->set('configValues.'.$row->id, '/mnt/photo-storage/web_images')
        ->call('updateHorizonStatus')
        ->call('save')
        ->assertViewHas('hasUnsavedChanges', false);

    expect($row->fresh()->value)->toBe('/mnt/photo-storage/web_images');
});
