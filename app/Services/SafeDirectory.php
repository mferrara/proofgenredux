<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use League\Flysystem\UnableToCreateDirectory;

/**
 * Create a directory that several workers may be creating at the same moment.
 *
 * Flysystem's local adapter checks for the directory and then creates it. When
 * two import workers hit a brand-new class together, one of them loses that
 * race (most easily on an external drive, where a recursive create is slow)
 * and throws although the directory now exists. Only a directory that really
 * is missing afterwards is a failure.
 */
class SafeDirectory
{
    private const ATTEMPTS = 3;

    public static function ensure(Filesystem $disk, string $directory): void
    {
        if ($directory === '' || $directory === '.' || $directory === '/') {
            return;
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                $disk->makeDirectory($directory);

                return;
            } catch (UnableToCreateDirectory $exception) {
                clearstatcache();

                if ($disk->directoryExists($directory)) {
                    return;
                }

                if ($attempt >= self::ATTEMPTS) {
                    throw $exception;
                }

                // The other worker may still be part-way through creating the
                // parent folders; give it a moment and try again.
                usleep(50_000 * $attempt);
            }
        }
    }
}
