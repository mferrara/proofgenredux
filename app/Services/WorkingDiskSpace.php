<?php

namespace App\Services;

/**
 * Free space on the volume that holds the working folder. A full disk in the
 * middle of a show stops imports and can damage the database, and nothing else
 * on the Mac warns about it in time.
 */
class WorkingDiskSpace
{
    public function freeBytes(): ?int
    {
        $path = (string) config('proofgen.fullsize_home_dir');
        $free = is_dir($path) ? @disk_free_space($path) : false;

        return $free === false ? null : (int) $free;
    }

    /** 'critical', 'low', or null when there is room (or it cannot be measured). */
    public function level(): ?string
    {
        $free = $this->freeBytes();

        return match (true) {
            $free === null => null,
            $free < config('proofgen.low_disk.critical_gb', 3) * 1024 ** 3 => 'critical',
            $free < config('proofgen.low_disk.warn_gb', 10) * 1024 ** 3 => 'low',
            default => null,
        };
    }

    public static function readable(int $bytes): string
    {
        return $bytes >= 1024 ** 3 ? round($bytes / 1024 ** 3, 1).' GB' : round($bytes / 1024 ** 2).' MB';
    }
}
