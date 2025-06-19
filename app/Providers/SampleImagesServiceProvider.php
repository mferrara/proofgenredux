<?php

namespace App\Providers;

use App\Services\SampleImagesService;
use Illuminate\Support\ServiceProvider;

class SampleImagesServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(SampleImagesService::class, function ($app) {
            return new SampleImagesService;
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
