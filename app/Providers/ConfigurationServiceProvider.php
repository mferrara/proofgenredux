<?php

namespace App\Providers;

use App\Models\Configuration;
use App\Services\ImageDiskConfigurator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

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
        if (! $this->canLoadConfigFromDatabase()) {
            Log::debug('Configuration table does not exist or is empty. Skipping loading configurations.');

            return;
        }

        try {
            // Load configuration overrides from cache/database and set them with the application config() helper
            // Log::debug('Loading configurations.');
            Configuration::overrideApplicationConfig();

            // Database-backed image roots must also update the underlying
            // Storage disk roots. Otherwise the UI config and actual writes
            // can point at different directories.
            app(ImageDiskConfigurator::class)->apply();

            // After configs are overlaid, reconfigure the remote_* storage disks
            // if the transport driver is set to 'local' so they map to a local
            // filesystem rather than SFTP.
            $this->applyTransportDriver();

        } catch (QueryException $e) {
            // Handle database connection failures gracefully
            // Just use the default configurations from files
            Log::debug('Error loading configurations: '.$e->getMessage());
        }
    }

    /**
     * When the transport driver is 'local', rewrite the SFTP-backed Storage disks
     * (remote_proofs / remote_web_images / remote_highres_images) as plain local
     * disks rooted at the configured paths. This way mkdir checks and directory
     * listing keep using Storage::disk() without having to branch on driver.
     */
    private function applyTransportDriver(): void
    {
        if (config('proofgen.sftp.driver', 'sftp') !== 'local') {
            return;
        }

        $diskMap = [
            'remote_proofs' => config('proofgen.sftp.path'),
            'remote_web_images' => config('proofgen.sftp.web_images_path'),
            'remote_highres_images' => config('proofgen.sftp.highres_images_path'),
        ];

        foreach ($diskMap as $disk => $root) {
            if (empty($root)) {
                continue;
            }
            config(["filesystems.disks.{$disk}" => [
                'driver' => 'local',
                'root' => $root,
                'throw' => false,
            ]]);
        }
    }

    /**
     * Check if we can load configurations from the database
     */
    private function canLoadConfigFromDatabase(): bool
    {
        // Check if the table exists and there is at least one row
        try {
            return Schema::hasTable('configurations') && Configuration::count() > 0;
        } catch (QueryException $e) {
            // Handle database connection failures gracefully
            // Just use the default configurations from files
            Log::debug('Error checking configurations table: '.$e->getMessage());

            return false;
        }
    }
}
