<?php

namespace App\Traits;

use App\Models\Photo;
use App\Models\ShowClass;
use App\Services\PathResolver;
use Illuminate\Support\Facades\Log;

/**
 * Shared evidence rules for upload syncs.
 *
 * Remote-side truth is whatever the last successful rsync reported through its
 * own `-ii` file list: on exit 0 every regular file in the manifest is in sync
 * at the destination (transferred files and unchanged files rsync found
 * already up to date). So:
 *
 *  - a real (non-dry) sync reconciles `*_uploaded_at` for photos whose local
 *    derivative files are all covered by the manifest. A missing stamp is
 *    backfilled; a present stamp is advanced only when this run actually
 *    transferred the photo's content again, so a byte-identical no-op retry
 *    keeps the timestamp and attribute-only changes never masquerade as a new
 *    upload;
 *  - a dry-run only ever *clears* a stale `*_uploaded_at` for photos rsync
 *    says would still be transferred. It never stamps: a would-be sync is not
 *    evidence of remote success;
 *  - nothing is stamped for absent/partial local derivatives (proofs require
 *    every configured suffix).
 *
 * Used by both Show (which fans out per class) and ShowClass.
 */
trait RsyncHandlerTrait
{
    /**
     * Apply evidence for one class.
     *
     * @param  array<int, string>  $syncedFiles  basenames rsync reported (transferred + unchanged)
     * @param  array<int, string>  $pendingFiles  basenames rsync would transfer (dry-run only)
     * @param  array<int, string>  $transferredFiles  basenames this run actually sent/received
     */
    protected function applyClassSyncEvidence(
        ShowClass $class,
        string $syncType,
        array $syncedFiles,
        array $pendingFiles,
        array $transferredFiles,
        bool $dryRun,
    ): void {
        $uploadedColumn = $this->syncTypeUploadColumn($syncType);
        $synced = array_flip($syncedFiles);
        $pending = array_flip($pendingFiles);
        $transferred = array_flip($transferredFiles);

        foreach ($class->photos()->get() as $photo) {
            /** @var Photo $photo */
            $expected = $this->expectedBasenamesForPhoto($photo, $syncType);

            if ($expected === []) {
                continue;
            }

            if ($dryRun) {
                $wouldTransfer = false;

                foreach ($expected as $basename) {
                    if (isset($pending[$basename])) {
                        $wouldTransfer = true;
                        break;
                    }
                }

                if ($wouldTransfer && $photo->{$uploadedColumn} !== null) {
                    Log::debug('Pending upload found for photo: '.$photo->id);
                    $photo->{$uploadedColumn} = null;
                    $photo->save();
                }

                continue;
            }

            $complete = true;
            $contentTransferred = false;

            foreach ($expected as $basename) {
                if (! isset($synced[$basename])) {
                    $complete = false;
                    break;
                }

                if (isset($transferred[$basename])) {
                    $contentTransferred = true;
                }
            }

            if (! $complete) {
                continue;
            }

            // A photo that was already uploaded keeps its stamp on a byte-for-byte
            // no-op retry, and only advances when this run really moved its bytes
            // again (a fresh upload or a changed derivative).
            if ($photo->{$uploadedColumn} === null || $contentTransferred) {
                Log::debug('Marking uploaded for photo: '.$photo->id);
                $photo->{$uploadedColumn} = now();
                $photo->save();
            }
        }
    }

    /**
     * Apply evidence for a show-level sync, fanning the manifest out per class.
     *
     * @param  array<int, string>  $syncedFiles  `{class}/{filename}` relative paths
     * @param  array<int, string>  $pendingFiles  `{class}/{filename}` relative paths (dry-run only)
     * @param  array<int, string>  $transferredFiles  `{class}/{filename}` relative paths
     */
    protected function applyShowLevelSyncEvidence(
        string $syncType,
        array $syncedFiles,
        array $pendingFiles,
        array $transferredFiles,
        bool $dryRun,
    ): void {
        $syncedByClass = $this->groupRelativeFilesByClass($syncedFiles);
        $pendingByClass = $this->groupRelativeFilesByClass($pendingFiles);
        $transferredByClass = $this->groupRelativeFilesByClass($transferredFiles);

        $classNames = $dryRun ? array_keys($pendingByClass) : array_keys($syncedByClass);

        foreach ($classNames as $className) {
            $class = ShowClass::where('id', $this->id.'_'.$className)->first();

            if (! $class) {
                Log::warning("Show class not found for ID: {$this->id}_{$className}");

                continue;
            }

            $this->applyClassSyncEvidence(
                $class,
                $syncType,
                $syncedByClass[$className] ?? [],
                $pendingByClass[$className] ?? [],
                $transferredByClass[$className] ?? [],
                $dryRun,
            );
        }
    }

    /**
     * Normalized (fullsize-relative) paths for a show-level sync result.
     *
     * @param  array<int, string>  $files  `{class}/{filename}` relative paths
     * @return array<int, string>
     */
    protected function showLevelSyncPaths(string $syncType, array $files): array
    {
        $resolver = app(PathResolver::class);
        $paths = [];

        foreach ($this->groupRelativeFilesByClass($files) as $className => $basenames) {
            $base = match ($syncType) {
                'proofs' => $resolver->getProofsPath($this->id, $className),
                'web_images' => $resolver->getWebImagesPath($this->id, $className),
                'highres_images' => $resolver->getHighresImagesPath($this->id, $className),
                default => null,
            };

            if ($base === null) {
                continue;
            }

            foreach ($basenames as $basename) {
                $paths[] = $resolver->normalizePath($base.'/'.$basename);
            }
        }

        return $paths;
    }

    /**
     * Normalized (fullsize-relative) paths for a single class's sync result.
     *
     * @param  array<int, string>  $files  basenames within this class
     * @return array<int, string>
     */
    protected function classSyncPaths(string $syncType, array $files): array
    {
        $resolver = app(PathResolver::class);
        $base = match ($syncType) {
            'proofs' => $this->proofs_path,
            'web_images' => $this->web_images_path,
            'highres_images' => $this->highres_images_path,
            default => null,
        };

        if ($base === null) {
            return [];
        }

        return array_values(array_map(
            fn (string $file) => $resolver->normalizePath($base.'/'.$file),
            $files,
        ));
    }

    /**
     * Proof uploads keyed by proof number (the historical ShowClass shape).
     *
     * @param  array<int, string>  $files  basenames within this class
     * @return array<string, array<int, string>>
     */
    protected function classProofPaths(array $files): array
    {
        $resolver = app(PathResolver::class);
        $grouped = [];

        foreach ($files as $file) {
            $proofNumber = $this->proofNumberFromPath($file);
            $grouped[$proofNumber][] = $resolver->normalizePath($this->proofs_path.'/'.$file);
        }

        return $grouped;
    }

    /**
     * @return array<int, string>
     */
    protected function expectedBasenamesForPhoto(Photo $photo, string $syncType): array
    {
        $suffixes = $this->syncTypeSuffixes($syncType);

        if ($suffixes === []) {
            return [];
        }

        $basenames = [];

        foreach ($suffixes as $suffix) {
            // Image's derivative generators always encode JPEGs with .jpg;
            // file_type describes the original and can instead be "jpeg".
            $basenames[] = $photo->proof_number.$suffix.'.jpg';
        }

        return $basenames;
    }

    /**
     * @return array<int, string>
     */
    protected function syncTypeSuffixes(string $syncType): array
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
     * Config key holding the destination base path for a sync type.
     */
    protected function syncTypeConfigKey(string $syncType): string
    {
        return match ($syncType) {
            'proofs' => 'proofgen.sftp.path',
            'web_images' => 'proofgen.sftp.web_images_path',
            'highres_images' => 'proofgen.sftp.highres_images_path',
            default => throw new \InvalidArgumentException("Unknown sync type: {$syncType}"),
        };
    }

    protected function syncTypeUploadColumn(string $syncType): string
    {
        return match ($syncType) {
            'proofs' => 'proofs_uploaded_at',
            'web_images' => 'web_image_uploaded_at',
            'highres_images' => 'highres_image_uploaded_at',
            default => throw new \InvalidArgumentException("Unknown sync type: {$syncType}"),
        };
    }

    /**
     * @param  array<int, string>  $files  `{class}/{filename}` relative paths
     * @return array<string, array<int, string>>
     */
    protected function groupRelativeFilesByClass(array $files): array
    {
        $grouped = [];

        foreach ($files as $relative) {
            if (! str_contains($relative, '/')) {
                continue;
            }

            [$className, $basename] = explode('/', $relative, 2);

            if ($className === '' || $basename === '' || str_contains($basename, '/')) {
                continue;
            }

            $grouped[$className][] = $basename;
        }

        return $grouped;
    }
}
