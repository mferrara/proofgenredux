<?php

namespace App\Helpers;

use App\Services\CoreImageDaemonService;
use App\Services\ImageEnhancementService;
use Illuminate\Support\Facades\Log;

class EnhancementServiceFactory
{
    /**
     * Get the best available enhancement service
     *
     * @param  string  $context  The context for logging (e.g., 'thumbnails', 'web images')
     */
    public static function getService(string $context = 'image'): ImageEnhancementService
    {
        // Try Core Image daemon first (macOS with Apple Silicon)
        if (PHP_OS_FAMILY === 'Darwin') {
            try {
                $daemonService = app(CoreImageDaemonService::class);
                if ($daemonService->isCoreImageAvailable()) {
                    // Log::info("Using Core Image daemon for {$context} enhancement (GPU accelerated)");

                    return $daemonService;
                }
            } catch (\Exception $e) {
                Log::warning('Failed to initialize Core Image daemon service: '.$e->getMessage());
            }
        }

        // Use the supported in-memory GD adjustments when Core Image is unavailable.
        Log::debug("Using standard ImageEnhancementService for {$context} (Core Image not available)");

        return app(ImageEnhancementService::class);
    }
}
