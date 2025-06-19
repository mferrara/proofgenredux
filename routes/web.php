<?php

use App\Livewire\HomeComponent;
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

    Route::get('/show/{show_id}', \App\Livewire\ShowViewComponent::class);
    Route::get('/show/{show}/class/{class}', \App\Livewire\ClassViewComponent::class);

    Route::get('/settings', \App\Livewire\ConfigComponent::class)->name('settings');
    Route::get('/config/server', \App\Livewire\ServerConnectionComponent::class)->name('server-connection');

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
