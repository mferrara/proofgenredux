<?php

namespace App\Providers;

use App\Services\PathResolver;
use Illuminate\Support\ServiceProvider;

class PathServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(PathResolver::class, function ($app) {
            return new PathResolver;
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
