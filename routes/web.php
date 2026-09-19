<?php

use App\Livewire\ClassViewComponent;
use App\Livewire\ConfigComponent;
use App\Livewire\GraveyardComponent;
use App\Livewire\HomeComponent;
use App\Livewire\PhotoIssuesComponent;
use App\Livewire\ShowViewComponent;
use App\Livewire\StorageProfilesComponent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {

    Route::get('/', HomeComponent::class)->name('home');

    Route::get('/show/{show_id}', ShowViewComponent::class);
    Route::get('/show/{show}/class/{class}', ClassViewComponent::class);

    // The small preview cameras embed in every JPEG: a contact sheet of a whole
    // card without decoding 20 MB files. Only serves files on mounted volumes.
    Route::get('/cards/thumb', function (Request $request) {
        $mount = (string) $request->query('m');
        $path = realpath($mount.'/'.ltrim((string) $request->query('p'), '/'));

        abort_unless($path !== false && str_starts_with($mount, '/Volumes/') && str_starts_with($path, rtrim($mount, '/').'/'), 404);

        $thumbnail = @exif_thumbnail($path);
        abort_if($thumbnail === false, 404);

        return response($thumbnail, 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=3600']);
    })->name('card-thumb');

    Route::get('/graveyard', GraveyardComponent::class)->name('graveyard');
    Route::get('/photo-issues', PhotoIssuesComponent::class)->name('photo-issues');

    Route::get('/settings', ConfigComponent::class)->name('settings');
    Route::get('/settings/storage-profiles', StorageProfilesComponent::class)->name('storage-profiles');

    // Route to serve temporary thumbnail previews
    Route::get('/temp/thumbnail-preview/{filename}', function ($filename) {
        $path = storage_path('app/temp/thumbnail-previews/'.$filename);

        if (! File::exists($path)) {
            abort(404);
        }

        return response()->file($path);
    })->name('thumbnail-preview');
});
