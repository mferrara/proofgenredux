<?php

use App\Livewire\ClassViewComponent;
use App\Models\Photo;
use App\Models\Show;
use App\Models\ShowClass;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('loads the configured large JPEG and clears stale modal state when another thumbnail is missing', function () {
    config(['testing.skip_file_operations' => true, 'proofgen.thumbnails.large.suffix' => '_large']);
    Storage::fake('fullsize');
    Show::withoutEvents(fn () => Show::create(['id' => 'DAD_SHOW', 'name' => 'Display name']));
    $class = ShowClass::withoutEvents(fn () => ShowClass::create([
        'id' => "DAD_SHOW_Dad's_class", 'show_id' => 'DAD_SHOW', 'name' => "Dad's_class",
    ]));
    $photo = Photo::create(['show_class_id' => $class->id, 'proof_number' => '100', 'file_type' => 'jpeg']);
    $missing = Photo::create(['show_class_id' => $class->id, 'proof_number' => '101', 'file_type' => 'jpg']);
    $path = "proofs/DAD_SHOW/Dad's_class/100_large.jpg";
    Storage::disk('fullsize')->put($path, 'synthetic JPEG bytes');

    Livewire::test(PhotoModalFixture::class)
        ->call('showPhotoModal', $photo->id)
        ->assertSet('showImageModal', true)
        ->assertSet('selectedPhotoId', $photo->id)
        ->assertSet('modalImageData.path', $path)
        ->assertSet('modalImageData.image', 'data:image/jpeg;base64,'.base64_encode('synthetic JPEG bytes'))
        ->call('showPhotoModal', $missing->id)
        ->assertSet('showImageModal', false)
        ->assertSet('selectedPhotoId', null)
        ->assertSet('modalImageData', null)
        ->assertDispatched('toast-show');
});

class PhotoModalFixture extends ClassViewComponent
{
    public function boot(): void {}

    public function mount(): void {}

    public function render()
    {
        return '<div></div>';
    }
}
