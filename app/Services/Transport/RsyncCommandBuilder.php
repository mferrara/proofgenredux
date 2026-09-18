<?php

namespace App\Services\Transport;

use App\Services\Delivery\DeliveryTarget;
use InvalidArgumentException;

/**
 * Builds rsync commands for pushing local files to a remote (SFTP) or local
 * destination, depending on the configured transport driver.
 *
 * Driver is read from `proofgen.sftp.driver` (default 'sftp'). When 'local',
 * the SSH transport is omitted and the destination is a plain filesystem
 * path on this machine — useful for local development against a sibling
 * Laravel install (e.g. ferraraphoto on the same Mac) and for any future
 * deployment where proofgen runs on the same host as the website.
 *
 * Two boundaries have to survive shell interpretation:
 *
 *  1. The local shell: callers historically ran the command through `exec()`.
 *     `build()` renders the argv with escapeshellarg() so that boundary is
 *     exact; new callers use `argv()`/`RsyncRunner` and skip the shell
 *     entirely.
 *  2. The remote shell: rsync passes the remote destination to ssh, which
 *     joins its argv with spaces and hands the string to the remote login
 *     shell. rsync itself backslash-escapes the remote path (it is passed
 *     through as a single argument), but the `-e`/`--rsh` value is split by
 *     rsync's own whitespace/quote-aware parser, so the SSH private key path
 *     must be quoted *for rsync* (not for the local shell) when it contains
 *     spaces.
 *
 * Output is deterministic: `-ii --out-format=%i %n` emits an 11-character
 * itemize marker, a space, then the path relative to the transfer root. The
 * repeated `-i` matters: rsync only reports *unchanged* entries (`.f` markers)
 * when `--itemize-changes` is given twice, and those entries are the evidence
 * that a file the sender enumerated is already in sync on the receiver.
 *
 * The SSH transport is bounded without weakening host-key verification:
 * `ConnectTimeout=15` fails an unreachable host quickly and `BatchMode=yes`
 * turns an authentication prompt into an immediate error instead of parking
 * the uploads queue worker for the whole process timeout.
 */
class RsyncCommandBuilder
{
    /**
     * Deterministic, parseable per-file output. `%i` is always 11 characters
     * (YXcstpoguax) and `%n` is the path relative to the transfer root.
     */
    public const OUT_FORMAT = '%i %n';

    /**
     * Build the rsync command as an argv array (no shell interpretation).
     *
     * @param  string  $localSource  Absolute local source directory (trailing slash = copy contents)
     * @param  string  $remoteBase  The configured base path on the destination (e.g. proofgen.sftp.path)
     * @param  string  $remoteSubdir  The relative subpath under that base (e.g. "Redux24/001")
     * @param  bool  $dryRun  Append --dry-run when true
     * @param  DeliveryTarget|null  $target  Resolved transport (driver/host/port/user); null reads proofgen.sftp.* as before
     * @return array<int, string>
     */
    public static function argv(string $localSource, string $remoteBase, string $remoteSubdir, bool $dryRun = false, ?DeliveryTarget $target = null, ?string $filesFrom = null): array
    {
        $argv = ['rsync', '-avz', '-ii', '--out-format='.self::OUT_FORMAT];

        // Only these files (one relative path per line): used to send a class in
        // small batches so the upload worker can yield to proofs between them.
        if ($filesFrom !== null) {
            $argv[] = '--files-from='.$filesFrom;
        }

        if ($dryRun) {
            $argv[] = '--dry-run';
        }

        $remoteSubdir = ltrim($remoteSubdir, '/');
        $destPath = rtrim($remoteBase, '/').($remoteSubdir === '' ? '' : '/'.$remoteSubdir);

        if ($target !== null ? ! $target->usesSsh() : self::driver() === 'local') {
            $argv[] = $localSource;
            $argv[] = $destPath;

            return $argv;
        }

        // The private key never comes from a target; it stays on this machine.
        $username = $target !== null ? $target->username : (string) config('proofgen.sftp.username', 'forge');
        $host = $target !== null ? $target->host : (string) config('proofgen.sftp.host', '');
        $key = (string) config('proofgen.sftp.private_key', '');
        $port = $target !== null ? $target->port : (int) config('proofgen.sftp.port', 0);

        $ssh = ['ssh', '-o', 'ConnectTimeout=15', '-o', 'BatchMode=yes'];

        if ($key !== '') {
            $ssh[] = '-i';
            $ssh[] = self::quoteForRsyncRsh($key);
        }

        if ($port > 0 && $port !== 22) {
            $ssh[] = '-p';
            $ssh[] = (string) $port;
        }

        $argv[] = '-e';
        $argv[] = implode(' ', $ssh);
        $argv[] = $localSource;
        $argv[] = ($username !== '' ? $username.'@' : '').$host.':'.$destPath;

        return $argv;
    }

    /**
     * Build the rsync command as a shell-safe string.
     *
     * Kept for compatibility (logging, `exec()`, existing callers/tests). Every
     * argv element is escaped for the local shell; the `-e` value keeps its
     * rsync-internal quoting intact because escapeshellarg() preserves it.
     */
    public static function build(string $localSource, string $remoteBase, string $remoteSubdir, bool $dryRun = false, ?DeliveryTarget $target = null): string
    {
        return implode(' ', array_map('escapeshellarg', self::argv($localSource, $remoteBase, $remoteSubdir, $dryRun, $target)));
    }

    private static function driver(): string
    {
        return (string) config('proofgen.sftp.driver', 'sftp');
    }

    /**
     * Quote one argument inside the string rsync splits for its remote shell
     * command (`-e`/`--rsh`). rsync's splitter honours single and double
     * quotes but performs no backslash escaping inside them, so:
     *
     *  - values without whitespace/quotes are passed as-is,
     *  - values containing a double quote use single quotes when possible,
     *  - a value containing both quote characters cannot be represented and
     *    raises instead of silently building a command that fails remotely.
     */
    private static function quoteForRsyncRsh(string $value): string
    {
        if ($value !== '' && preg_match('/^[A-Za-z0-9_@%+=:,.\/-]+$/', $value) === 1) {
            return $value;
        }

        if (! str_contains($value, '"')) {
            return '"'.$value.'"';
        }

        if (! str_contains($value, "'")) {
            return "'".$value."'";
        }

        throw new InvalidArgumentException(
            'Cannot quote an ssh private key path containing both single and double quotes for rsync -e.'
        );
    }
}
