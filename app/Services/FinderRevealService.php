<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Opens a macOS Finder window with the given file selected.
 *
 * Single-tenant local desktop app — the operator IS the only user, so shelling
 * out to `open -R` is safe. We still validate the path lives under one of our
 * configured disk roots to prevent typos/programmer-mistakes from selecting
 * arbitrary files.
 */
class FinderRevealService
{
    /**
     * Disks whose roots are valid targets for reveal.
     *
     * @var array<int, string>
     */
    private const ALLOWED_DISKS = ['fullsize', 'archive'];

    public function reveal(string $absolutePath): void
    {
        $this->assertPathIsWithinKnownDisk($absolutePath);

        Process::run(['open', '-R', $absolutePath]);
    }

    private function assertPathIsWithinKnownDisk(string $absolute): void
    {
        $absolute = trim($absolute);
        if ($absolute === '') {
            throw new RuntimeException('Refusing to reveal empty path.');
        }

        // Reject any path that walks up directories — the disk-root prefix check
        // alone is insufficient because "{root}/../etc/passwd" does start with
        // "{root}/" textually but escapes the disk after normalization.
        if (str_contains($absolute, '/../') || str_ends_with($absolute, '/..')) {
            throw new RuntimeException("Refusing to reveal path containing parent traversal: {$absolute}");
        }

        $needle = rtrim($absolute, '/').'/';
        foreach (self::ALLOWED_DISKS as $disk) {
            $root = rtrim((string) config("filesystems.disks.{$disk}.root"), '/');
            if ($root !== '' && str_starts_with($needle, $root.'/')) {
                return;
            }
        }

        throw new RuntimeException("Refusing to reveal path outside known disks: {$absolute}");
    }
}
