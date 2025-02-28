<?php

namespace App\Providers;

use App\Models\Configuration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;

class ConfigurationServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Only run if the configurations table exists
        if (!$this->canLoadConfigFromDatabase()) {
            Log::debug('Configuration table does not exist or is empty. Skipping loading configurations.');
            return;
        }

        try {
            // Load configuration overrides from cache/database and set them with the application config() helper
            Log::debug('Loading configurations.');
            Configuration::overrideApplicationConfig();

        } catch (QueryException $e) {
            // Handle database connection failures gracefully
            // Just use the default configurations from files
            Log::debug('Error loading configurations: ' . $e->getMessage());
        }
    }

    /**
     * Check if we can load configurations from the database
     *
     * @return bool
     */
    private function canLoadConfigFromDatabase(): bool
    {
        // Check if the table exists and there is at least one row
        try {
            return Schema::hasTable('configurations') && Configuration::count() > 0;
        } catch (QueryException $e) {
            // Handle database connection failures gracefully
            // Just use the default configurations from files
            Log::debug('Error checking configurations table: ' . $e->getMessage());
            return false;
        }
    }
}
