<?php

use App\Livewire\ClassViewComponent;
use App\Livewire\ConfigComponent;
use App\Livewire\GraveyardComponent;
use App\Livewire\HomeComponent;
use App\Livewire\PhotoIssuesComponent;
use App\Livewire\ServerConnectionComponent;
use App\Livewire\ShowViewComponent;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

Route::get('/test', function () {
    return 'Hello World';
});

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {

    Route::get('/', HomeComponent::class)->name('home');

    Route::get('/show/{show_id}', ShowViewComponent::class);
    Route::get('/show/{show}/class/{class}', ClassViewComponent::class);

    Route::get('/graveyard', GraveyardComponent::class)->name('graveyard');
    Route::get('/photo-issues', PhotoIssuesComponent::class)->name('photo-issues');

    Route::get('/settings', ConfigComponent::class)->name('settings');
    Route::get('/config/server', ServerConnectionComponent::class)->name('server-connection');

    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');

    // Route to serve temporary thumbnail previews
    Route::get('/temp/thumbnail-preview/{filename}', function ($filename) {
        $path = storage_path('app/temp/thumbnail-previews/'.$filename);

        if (! File::exists($path)) {
            abort(404);
        }

        return response()->file($path);
    })->name('thumbnail-preview');
});
