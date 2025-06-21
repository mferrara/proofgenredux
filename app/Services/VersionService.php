<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

class VersionService
{
    /**
     * Get the application version from git tags or fallback
     */
    public static function getVersion(): string
    {
        // Cache the version for 1 hour to avoid running git commands on every request
        return Cache::remember('app_version', 3600, function () {
            // First, check if we have a VERSION file (useful for deployments)
            if (file_exists(base_path('VERSION'))) {
                return trim(file_get_contents(base_path('VERSION')));
            }

            // Try to get version from git
            try {
                $result = Process::run('git describe --tags --always');
                
                if ($result->successful() && $result->output()) {
                    return trim($result->output());
                }
                
                // If no tags, try to get commit hash
                $result = Process::run('git rev-parse --short HEAD');
                if ($result->successful() && $result->output()) {
                    return 'dev-' . trim($result->output());
                }
            } catch (\Exception $e) {
                // Git not available or other error
            }

            // Fallback to config or default
            return config('app.version', '1.0.0');
        });
    }

    /**
     * Get a clean version number (without commit info)
     */
    public static function getCleanVersion(): string
    {
        $version = self::getVersion();
        
        // If it's a clean tag, return as is
        if (preg_match('/^v?\d+\.\d+\.\d+$/', $version)) {
            return $version;
        }
        
        // If it's a tag with commits after (e.g., v1.1.0-2-g06bbc25)
        if (preg_match('/^(v?\d+\.\d+\.\d+)-\d+-g[a-f0-9]+$/', $version, $matches)) {
            return $matches[1] . '+';
        }
        
        return $version;
    }

    /**
     * Clear the version cache (useful after deployments)
     */
    public static function clearCache(): void
    {
        Cache::forget('app_version');
    }
}