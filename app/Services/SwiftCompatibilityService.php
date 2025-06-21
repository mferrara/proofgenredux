<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SwiftCompatibilityService
{
    private const CACHE_FILE = 'swift-compatibility.json';
    private const MIN_SWIFT_VERSION = '5.5';

    /**
     * Check Swift compatibility for Core Image enhancement
     *
     * @param bool $force Force a fresh check, ignoring cache
     * @return array
     */
    public function checkCompatibility(bool $force = false): array
    {
        $cacheFile = storage_path(self::CACHE_FILE);

        // Return cached result if available and not forced
        if (!$force && file_exists($cacheFile)) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if ($cached && isset($cached['checked_at'])) {
                // Log::debug('Using cached Swift compatibility check from ' . $cached['checked_at']);
                return $cached;
            }
        }

        // Log::info('Performing Swift compatibility check');

        $result = [
            'compatible' => false,
            'swift_available' => false,
            'version' => null,
            'error' => null,
            'minimum_version' => self::MIN_SWIFT_VERSION,
            'checked_at' => now()->toISOString(),
            'platform' => PHP_OS_FAMILY
        ];

        // Only check on macOS
        if (PHP_OS_FAMILY !== 'Darwin') {
            $result['error'] = 'Core Image enhancement requires macOS';
            $this->cacheResult($result);
            return $result;
        }

        // Check Swift availability
        // First try common locations directly (to avoid PATH issues in web/worker contexts)
        $knownPaths = [
            '/usr/bin/swift',
            '/usr/local/bin/swift',
            '/opt/homebrew/bin/swift',
        ];
        
        $swiftPath = null;
        foreach ($knownPaths as $path) {
            if (file_exists($path) && is_executable($path)) {
                $swiftPath = $path;
                break;
            }
        }
        
        // Fallback to which command if not found in known locations
        if (!$swiftPath) {
            $swiftPath = trim(shell_exec('which swift 2>/dev/null'));
        }
        
        if (empty($swiftPath)) {
            $result['error'] = 'Swift not found. Please install Xcode Command Line Tools or Xcode.';
            Log::warning('Swift not found in PATH or known locations. PATH=' . getenv('PATH'));
            $this->cacheResult($result);
            return $result;
        }

        $result['swift_available'] = true;
        // Log::debug("Swift found at: {$swiftPath}");

        // Check Swift version
        $versionOutput = shell_exec('swift --version 2>&1');
        if (preg_match('/Swift version (\d+\.\d+(?:\.\d+)?)/', $versionOutput, $matches)) {
            $result['version'] = $matches[1];
            // Log::info("Swift version detected: {$result['version']}");

            if (version_compare($result['version'], self::MIN_SWIFT_VERSION, '>=')) {
                $result['compatible'] = true;
                // Log::info('Swift version is compatible for Core Image enhancement');
            } else {
                $result['error'] = "Swift {$result['version']} found, but version " . self::MIN_SWIFT_VERSION . " or higher is required.";
                Log::warning($result['error']);
            }
        } else {
            $result['error'] = 'Could not determine Swift version.';
            Log::warning('Failed to parse Swift version from: ' . $versionOutput);
        }

        $this->cacheResult($result);
        return $result;
    }

    /**
     * Cache the compatibility check result
     *
     * @param array $result
     * @return void
     */
    private function cacheResult(array $result): void
    {
        $cacheFile = storage_path(self::CACHE_FILE);
        $json = json_encode($result, JSON_PRETTY_PRINT);
        
        if (file_put_contents($cacheFile, $json) !== false) {
            // Log::debug('Swift compatibility check cached to: ' . $cacheFile);
        } else {
            Log::warning('Failed to cache Swift compatibility check');
        }
    }

    /**
     * Clear the cached compatibility check
     *
     * @return void
     */
    public function clearCache(): void
    {
        $cacheFile = storage_path(self::CACHE_FILE);
        if (file_exists($cacheFile)) {
            if (unlink($cacheFile)) {
                // Log::info('Swift compatibility cache cleared');
            } else {
                Log::warning('Failed to clear Swift compatibility cache');
            }
        }
    }

    /**
     * Get installation instructions for Swift
     *
     * @return array
     */
    public function getInstallationInstructions(): array
    {
        return [
            'xcode' => 'Install Xcode from the Mac App Store',
            'command_line_tools' => 'Install Xcode Command Line Tools by running: xcode-select --install',
            'verification' => 'After installation, verify with: swift --version'
        ];
    }
}