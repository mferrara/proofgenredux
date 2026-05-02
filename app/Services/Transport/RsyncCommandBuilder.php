<?php

namespace App\Services\Transport;

/**
 * Builds rsync commands for pushing local files to a remote (SFTP) or local
 * destination, depending on the configured transport driver.
 *
 * Driver is read from `proofgen.sftp.driver` (default 'sftp'). When 'local',
 * the SSH transport is omitted and the destination is a plain filesystem
 * path on this machine — useful for local development against a sibling
 * Laravel install (e.g. ferraraphoto on the same Mac) and for any future
 * deployment where proofgen runs on the same host as the website.
 */
class RsyncCommandBuilder
{
    /**
     * Build the rsync command.
     *
     * @param  string  $localSource  Absolute local source directory (with trailing /)
     * @param  string  $remoteBase  The configured base path on the destination (e.g. proofgen.sftp.path)
     * @param  string  $remoteSubdir  The relative subpath under that base (e.g. "Redux24/001")
     * @param  bool  $dryRun  Append --dry-run when true
     */
    public static function build(string $localSource, string $remoteBase, string $remoteSubdir, bool $dryRun = false): string
    {
        $driver = config('proofgen.sftp.driver', 'sftp');
        $dry = $dryRun ? '--dry-run' : '';
        $destPath = rtrim($remoteBase, '/').'/'.ltrim($remoteSubdir, '/');

        if ($driver === 'local') {
            return trim('rsync -avz '.$dry.' '.escapeshellarg($localSource).' '.escapeshellarg($destPath));
        }

        $user = config('proofgen.sftp.username', 'forge');
        $host = config('proofgen.sftp.host');
        $key = config('proofgen.sftp.private_key');

        return trim(
            'rsync -avz '.$dry.
            ' -e "ssh -i '.$key.'" '.
            escapeshellarg($localSource).' '.
            $user.'@'.$host.':'.$destPath
        );
    }
}
