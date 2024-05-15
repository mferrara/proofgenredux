<?php

use App\Livewire\HomeComponent;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {

    Route::get('/', HomeComponent::class)->name('home');

    Route::get('/show/{show}', \App\Livewire\ShowViewComponent::class);
    Route::get('/show/{show}/class/{class}', \App\Livewire\ClassViewComponent::class);

    Route::get('/config/server', \App\Livewire\ServerConnectionComponent::class)->name('server-connection');

    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');
});
