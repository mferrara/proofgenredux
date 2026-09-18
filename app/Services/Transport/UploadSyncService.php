<?php

namespace App\Services\Transport;

use App\Services\Delivery\DeliveryTarget;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates one upload/dry-run sync for a single sync type ("proofs",
 * "web_images", "highres_images").
 *
 * Responsibilities:
 *  - build the argv via {@see RsyncCommandBuilder},
 *  - execute it through the injectable {@see RsyncRunner} (throwing on any
 *    non-zero exit rather than letting callers inspect `$returnCode`),
 *  - turn rsync's own `-ii` itemize output into the successful-sync manifest
 *    (transferred + unchanged files) and the dry-run pending set.
 *
 * No database work happens here; models/traits decide what the evidence means
 * for their Photo rows.
 */
class UploadSyncService
{
    public function __construct(
        private RsyncRunner $runner,
    ) {}

    public function sync(
        string $syncType,
        string $localRoot,
        string $remoteBase,
        string $remoteSubdir,
        bool $dryRun = false,
        ?DeliveryTarget $target = null,
        ?array $onlyFiles = null,
    ): UploadSyncResult {
        // No local source directory means there is nothing to transfer. Skipping
        // the rsync invocation keeps routine "nothing to upload yet" checks from
        // turning into hard failures (rsync would exit 23 on the missing dir).
        if (! is_dir($localRoot)) {
            Log::debug('Upload sync skipped: local source directory does not exist', [
                'sync_type' => $syncType,
                'dry_run' => $dryRun,
            ]);

            return new UploadSyncResult($syncType, $dryRun, [], [], []);
        }

        $suffixes = $this->suffixesFor($syncType);

        $filesFrom = null;
        if ($onlyFiles !== null) {
            if ($onlyFiles === []) {
                return new UploadSyncResult($syncType, $dryRun, [], [], []);
            }
            $filesFrom = tempnam(sys_get_temp_dir(), 'proofgen-rsync-');
            file_put_contents($filesFrom, implode("\n", $onlyFiles)."\n");
        }

        $argv = RsyncCommandBuilder::argv($localRoot, $remoteBase, $remoteSubdir, $dryRun, $target, $filesFrom);

        try {
            $result = $this->runner->run($argv);
        } finally {
            if ($filesFrom !== null) {
                @unlink($filesFrom);
            }
        }

        // The manifest comes from rsync's own file list. A pre-run or post-run
        // directory walk is not upload evidence: rsync may never have seen a
        // file that appeared after (or vanished before) its own enumeration.
        $syncedFiles = $this->matchingFiles($result->syncedFiles(), $suffixes);
        $transferredFiles = $this->matchingFiles($result->transferredFiles(), $suffixes);
        $pendingFiles = $dryRun ? $transferredFiles : [];

        Log::debug('Upload sync completed', [
            'sync_type' => $syncType,
            'dry_run' => $dryRun,
            'synced_files' => count($syncedFiles),
            'pending_files' => count($pendingFiles),
            'transferred_files' => count($transferredFiles),
        ]);

        return new UploadSyncResult($syncType, $dryRun, $syncedFiles, $pendingFiles, $transferredFiles);
    }

    /**
     * Suffixes that identify files belonging to a sync type.
     *
     * @return array<int, string>
     */
    public function suffixesFor(string $syncType): array
    {
        $suffixes = match ($syncType) {
            'proofs' => array_map(
                fn (array $values) => (string) ($values['suffix'] ?? ''),
                array_values((array) config('proofgen.thumbnails', [])),
            ),
            'web_images' => [(string) config('proofgen.web_images.suffix', '')],
            'highres_images' => [(string) config('proofgen.highres_images.suffix', '')],
            default => [],
        };

        return array_values(array_filter($suffixes, fn (string $suffix) => $suffix !== ''));
    }

    /**
     * @param  array<int, string>  $files  relative paths
     * @param  array<int, string>  $suffixes
     * @return array<int, string>
     */
    private function matchingFiles(array $files, array $suffixes): array
    {
        if ($suffixes === []) {
            return [];
        }

        return array_values(array_filter($files, function (string $file) use ($suffixes): bool {
            $stem = pathinfo($file, PATHINFO_FILENAME);

            foreach ($suffixes as $suffix) {
                if (str_ends_with($stem, $suffix)) {
                    return true;
                }
            }

            return false;
        }));
    }
}
