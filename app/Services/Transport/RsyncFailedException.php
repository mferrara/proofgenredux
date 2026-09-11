<?php

namespace App\Services\Transport;

use RuntimeException;

/**
 * Raised whenever an rsync invocation does not complete successfully: a
 * non-zero exit, a timeout, or a failure to even start the binary.
 *
 * The message carries a bounded stderr tail (the useful part of rsync/ssh
 * diagnostics) plus the exit code. It deliberately never contains the full
 * argv or any credential material.
 */
class RsyncFailedException extends RuntimeException
{
    public const STDERR_LIMIT = 4000;

    public static function fromResult(RsyncRunResult $result): self
    {
        $message = 'rsync failed with exit code '.$result->exitCode.'.';
        $stderr = self::boundedDiagnostic($result->stderr);

        if ($stderr !== '') {
            $message .= ' stderr: '.$stderr;
        }

        return new self($message, $result->exitCode);
    }

    public static function timedOut(int $seconds): self
    {
        return new self('rsync timed out after '.$seconds.' seconds.');
    }

    public static function couldNotStart(string $detail = ''): self
    {
        $message = 'rsync could not be started.';

        if ($detail !== '') {
            $message .= ' '.$detail;
        }

        return new self($message);
    }

    private static function boundedDiagnostic(string $output): string
    {
        $output = trim($output);

        if (mb_strlen($output) > self::STDERR_LIMIT) {
            // The tail of stderr is where rsync/ssh put the actionable error.
            return '…'.mb_substr($output, -self::STDERR_LIMIT);
        }

        return $output;
    }
}
