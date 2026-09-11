<?php

namespace App\Services\Transport;

/**
 * Evidence gathered from one rsync sync attempt (real or dry-run).
 *
 * `syncedFiles` is the successful-sync manifest: the regular files rsync
 * itself reported (`-ii` output), i.e. everything it transferred plus the
 * unchanged files it found already up to date. It is built from rsync's own
 * file enumeration, never from a separate local directory walk, so a file
 * that appears or disappears around the run cannot be mistaken for evidence.
 * Proof completeness is judged from this list only.
 *
 * `pendingFiles` is only populated for dry-runs and lists the paths rsync
 * would transfer. It is the authoritative "pending upload" set.
 *
 * `transferredFiles` lists the content transfers actually performed by this
 * run (equal to `pendingFiles` under --dry-run), which lets callers bump an
 * upload timestamp only when bytes really moved again.
 *
 * All paths are relative to the transfer root (for class uploads that is the
 * class directory; for show uploads `{class}/{filename}`).
 */
final class UploadSyncResult
{
    /**
     * @param  array<int, string>  $syncedFiles
     * @param  array<int, string>  $pendingFiles
     * @param  array<int, string>  $transferredFiles
     */
    public function __construct(
        public readonly string $syncType,
        public readonly bool $dryRun,
        public readonly array $syncedFiles,
        public readonly array $pendingFiles,
        public readonly array $transferredFiles,
    ) {}
}
