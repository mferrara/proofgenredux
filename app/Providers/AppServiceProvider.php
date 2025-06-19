<?php

namespace App\Providers;

use App\Services\HorizonService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register HorizonService as a singleton
        $this->app->singleton(HorizonService::class, function ($app) {
            return new HorizonService;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
