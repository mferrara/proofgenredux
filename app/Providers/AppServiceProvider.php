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
        // Clear Swift compatibility cache on deployment
        if (app()->environment('production')) {
            $this->clearSwiftCacheOnDeployment();
        }
    }

    /**
     * Clear Swift compatibility cache if deployment is detected
     */
    private function clearSwiftCacheOnDeployment(): void
    {
        $lockFile = storage_path('deployment.lock');
        $deploymentFlag = storage_path('.deployment-marker');
        
        // Check if deployment marker exists and is newer than lock file
        if (file_exists($deploymentFlag)) {
            $shouldClear = false;
            
            if (!file_exists($lockFile)) {
                $shouldClear = true;
            } else {
                // Clear if deployment marker is newer than lock file
                if (filemtime($deploymentFlag) > filemtime($lockFile)) {
                    $shouldClear = true;
                }
            }
            
            if ($shouldClear) {
                try {
                    app(\App\Services\SwiftCompatibilityService::class)->clearCache();
                    touch($lockFile);
                    \Illuminate\Support\Facades\Log::info('Swift compatibility cache cleared on deployment');
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning('Failed to clear Swift compatibility cache: ' . $e->getMessage());
                }
            }
        }
    }
}
