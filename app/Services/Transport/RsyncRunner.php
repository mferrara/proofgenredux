<?php

namespace App\Services\Transport;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use Throwable;

/**
 * The single place real rsync processes are started.
 *
 * Wraps Laravel's Process facade so every upload path shares the same
 * behaviour: an explicit timeout, no shell interpretation of the argv, and a
 * thrown {@see RsyncFailedException} on any non-zero exit (never a silent
 * partial success). The binary can be overridden for tests that need a
 * scripted fake.
 */
class RsyncRunner
{
    public const DEFAULT_TIMEOUT_SECONDS = 1800; // per-process budget; upload jobs include additional overhead

    public function __construct(
        private ?string $rsyncBinary = null,
        private ?int $timeoutSeconds = null,
    ) {}

    private function installedBinary(): string
    {
        if ($configured = config('proofgen.sftp.rsync_binary')) {
            return $configured;
        }

        // Herd's PATH can select Apple's openrsync. Its output does not provide
        // the rsync 3 itemized completion evidence our upload accounting needs.
        foreach (['/opt/homebrew/bin/rsync', '/usr/local/bin/rsync'] as $binary) {
            if (is_executable($binary)) {
                return $binary;
            }
        }

        throw RsyncFailedException::couldNotStart(
            'Install Homebrew rsync (brew install rsync), or set RSYNC_BINARY to your rsync 3 executable.'
        );
    }

    /**
     * @param  array<int, string>  $argv  Full rsync argv (argv[0] = binary)
     *
     * @throws RsyncFailedException on non-zero exit, timeout, or start failure
     */
    public function run(array $argv): RsyncRunResult
    {
        if ($argv === []) {
            throw new InvalidArgumentException('Cannot run rsync with an empty argv.');
        }

        $argv[0] = $this->rsyncBinary ?: $this->installedBinary();

        $timeout = $this->timeoutSeconds ?? (int) config('proofgen.sftp.timeout', self::DEFAULT_TIMEOUT_SECONDS);

        if ($timeout <= 0) {
            $timeout = self::DEFAULT_TIMEOUT_SECONDS;
        }

        $timeout = max(1, $timeout);

        try {
            $result = Process::timeout($timeout)->run($argv);
        } catch (ProcessTimedOutException) {
            // Symfony's timeout message embeds the full command line, so the
            // rsync argv is deliberately never part of the public error text.
            throw RsyncFailedException::timedOut($timeout);
        } catch (Throwable $e) {
            // Symfony's start-failure message ("The command \"…\" failed.")
            // embeds the full argv as well. Only the exception class is safe to
            // keep; the operation and remediation are described separately.
            throw RsyncFailedException::couldNotStart(
                'Cause: '.class_basename($e).'. Check that rsync is installed and executable.'
            );
        }

        $run = new RsyncRunResult(
            (int) ($result->exitCode() ?? 1),
            (string) $result->output(),
            (string) $result->errorOutput(),
        );

        if (! $run->successful()) {
            // 126 / 127 are the local shell's "found but not executable" and
            // "command not found" codes. Their stderr echoes the full argv, so
            // they take the sanitized start-failure path instead of forwarding
            // raw diagnostics. Every other non-zero exit keeps its bounded
            // stderr (real rsync failures put the actionable error there).
            if (in_array($run->exitCode, [126, 127], true)) {
                throw RsyncFailedException::couldNotStart(
                    'The local shell could not execute the binary (exit code '.$run->exitCode.'). Check that rsync is installed and executable.'
                );
            }

            throw RsyncFailedException::fromResult($run);
        }

        return $run;
    }
}
