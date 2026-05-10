<?php

namespace App\Providers;

use App\Services\Ferraraphoto\FerraraphotoApiClient;
use App\Services\HorizonService;
use App\Services\SwiftCompatibilityService;
use Illuminate\Support\Facades\Log;
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

        $this->app->singleton(FerraraphotoApiClient::class, fn () => new FerraraphotoApiClient(
            baseUrl: (string) config('proofgen.ferraraphoto.base_url', 'https://ferraraphoto.com'),
            apiToken: (string) config('proofgen.ferraraphoto.api_token', ''),
            logger: Log::getLogger(),
        ));
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

            if (! file_exists($lockFile)) {
                $shouldClear = true;
            } else {
                // Clear if deployment marker is newer than lock file
                if (filemtime($deploymentFlag) > filemtime($lockFile)) {
                    $shouldClear = true;
                }
            }

            if ($shouldClear) {
                try {
                    app(SwiftCompatibilityService::class)->clearCache();
                    touch($lockFile);
                    Log::info('Swift compatibility cache cleared on deployment');
                } catch (\Exception $e) {
                    Log::warning('Failed to clear Swift compatibility cache: '.$e->getMessage());
                }
            }
        }
    }
}
