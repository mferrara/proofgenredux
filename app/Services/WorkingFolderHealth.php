<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Is the working folder somewhere macOS hands to iCloud?
 *
 * With "Desktop & Documents Folders" and "Optimize Mac Storage" on, macOS
 * evicts files under ~/Documents and ~/Desktop: the name and size stay local,
 * the contents are fetched on demand. For Proofgen that means slow or failing
 * reads ("Unable to decode input"), failures offline, originals that can vanish
 * mid-show, and iCloud traffic competing with proof uploads on show wifi.
 */
class WorkingFolderHealth
{
    private const CACHE_SECONDS = 600;

    /**
     * @return array{path: string, reason: string}|null a problem to show the operator, or null
     */
    public function problem(): ?array
    {
        $path = rtrim((string) config('proofgen.fullsize_home_dir'), '/');

        if ($path === '') {
            return null;
        }

        return Cache::remember('working-folder-health:'.sha1($path), self::CACHE_SECONDS, fn () => $this->inspect($path)) ?: null;
    }

    /**
     * @return array{path: string, reason: string}|false false = healthy (cacheable, unlike null)
     */
    protected function inspect(string $path): array|false
    {
        $home = $this->homeDirectory();

        if ($home !== null) {
            if (str_starts_with($path.'/', $home.'/Library/Mobile Documents/')) {
                return ['path' => $path, 'reason' => 'it is inside iCloud Drive'];
            }

            foreach (['Documents', 'Desktop'] as $folder) {
                if (str_starts_with($path.'/', $home.'/'.$folder.'/')
                    && is_dir($home.'/Library/Mobile Documents/com~apple~CloudDocs/'.$folder)) {
                    return ['path' => $path, 'reason' => 'iCloud syncs your '.$folder.' folder'];
                }
            }
        }

        if ($this->hasEvictedFiles($path)) {
            return ['path' => $path, 'reason' => 'some files in it have been moved to iCloud and are no longer stored on this Mac'];
        }

        return false;
    }

    /**
     * macOS marks evicted files "dataless". Stops at the first one found.
     */
    protected function hasEvictedFiles(string $path): bool
    {
        if (PHP_OS_FAMILY !== 'Darwin' || ! is_dir($path)) {
            return false;
        }

        try {
            $result = Process::timeout(20)->run(['/usr/bin/find', $path, '-flags', '+dataless', '-print', '-quit']);

            return $result->successful() && trim($result->output()) !== '';
        } catch (Throwable) {
            return false;
        }
    }

    protected function homeDirectory(): ?string
    {
        $home = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['dir'] ?? null) : null;
        $home = $home ?: (getenv('HOME') ?: null);

        return $home ? rtrim($home, '/') : null;
    }
}
