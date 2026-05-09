<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class GraveyardService
{
    public const DISKS = ['fullsize', 'archive'];

    private const SIDECAR_EXTENSION = '.json';

    public function __construct(private ?PathResolver $pathResolver = null)
    {
        $this->pathResolver ??= app(PathResolver::class);
    }

    /**
     * Per-disk summary: file_count, oldest_buried_at (Carbon|null),
     * total_bytes, aged_file_count (older than configured aged_days).
     *
     * @return array<string, array{file_count:int, oldest_buried_at:?Carbon, total_bytes:int, aged_file_count:int}>
     */
    public function summary(): array
    {
        $agedDays = $this->agedDays();
        $cutoff = Carbon::now()->subDays($agedDays);
        $out = [];

        foreach (self::DISKS as $disk) {
            $fileCount = 0;
            $totalBytes = 0;
            $oldest = null;
            $aged = 0;

            foreach ($this->dataFiles($disk) as $entry) {
                $fileCount++;
                $totalBytes += $entry['size'];

                $buriedAt = $entry['buried_at'];
                if ($buriedAt !== null) {
                    if ($oldest === null || $buriedAt->lt($oldest)) {
                        $oldest = $buriedAt;
                    }
                    if ($buriedAt->lt($cutoff)) {
                        $aged++;
                    }
                }
            }

            $out[$disk] = [
                'file_count' => $fileCount,
                'oldest_buried_at' => $oldest,
                'total_bytes' => $totalBytes,
                'aged_file_count' => $aged,
            ];
        }

        return $out;
    }

    /**
     * Entries on $disk, oldest first. Pulls metadata from JSON sidecars when present,
     * falling back to filesystem mtime/size when a sidecar is missing or unreadable.
     *
     * @return array<int, array{graveyard_path:string, sidecar_path:?string, original_path:?string, sha1:?string, size:int, reason:?string, buried_at:?Carbon, context:array}>
     */
    public function listEntries(string $disk, int $limit = 100, int $offset = 0): array
    {
        $entries = [];

        foreach ($this->dataFiles($disk) as $entry) {
            $entries[] = $entry;
        }

        usort($entries, function ($a, $b) {
            $aTs = $a['buried_at']?->getTimestamp() ?? PHP_INT_MAX;
            $bTs = $b['buried_at']?->getTimestamp() ?? PHP_INT_MAX;

            return $aTs <=> $bTs;
        });

        return array_slice($entries, $offset, $limit);
    }

    /**
     * Delete graveyard files + sidecars older than $days on $disk.
     *
     * Graveyard contents are explicitly operator-purgeable; everything else routes
     * data files into the graveyard, never out of it. Keep this as the single
     * sanctioned graveyard delete path so the always-on never-delete invariant
     * has exactly one documented escape hatch.
     *
     * @return array{deleted_count:int, freed_bytes:int}
     */
    public function purgeOlderThan(string $disk, int $days): array
    {
        $cutoff = Carbon::now()->subDays($days);
        $storage = Storage::disk($disk);
        $deleted = 0;
        $freed = 0;

        foreach ($this->dataFiles($disk) as $entry) {
            $buriedAt = $entry['buried_at'];
            if ($buriedAt === null || ! $buriedAt->lt($cutoff)) {
                continue;
            }

            $storage->delete($entry['graveyard_path']);
            $freed += $entry['size'];
            $deleted++;

            if ($entry['sidecar_path'] !== null && $storage->exists($entry['sidecar_path'])) {
                $storage->delete($entry['sidecar_path']);
            }
        }

        return [
            'deleted_count' => $deleted,
            'freed_bytes' => $freed,
        ];
    }

    public function agedDays(): int
    {
        return (int) (config('proofgen.graveyard.aged_days') ?? 90);
    }

    /**
     * @return iterable<int, array{graveyard_path:string, sidecar_path:?string, original_path:?string, sha1:?string, size:int, reason:?string, buried_at:?Carbon, context:array}>
     */
    private function dataFiles(string $disk): iterable
    {
        $storage = Storage::disk($disk);
        $root = $this->graveyardRoot();

        if (! $storage->exists($root)) {
            return;
        }

        foreach ($storage->allFiles($root) as $path) {
            if (str_ends_with($path, self::SIDECAR_EXTENSION)) {
                continue;
            }

            $sidecarPath = $path.self::SIDECAR_EXTENSION;
            $sidecar = null;
            if ($storage->exists($sidecarPath)) {
                $decoded = json_decode((string) $storage->get($sidecarPath), true);
                if (is_array($decoded)) {
                    $sidecar = $decoded;
                }
            }

            yield [
                'graveyard_path' => $path,
                'sidecar_path' => $sidecar !== null ? $sidecarPath : null,
                'original_path' => $sidecar['original_path'] ?? null,
                'sha1' => $sidecar['sha1'] ?? null,
                'size' => isset($sidecar['size']) ? (int) $sidecar['size'] : (int) $storage->size($path),
                'reason' => $sidecar['reason'] ?? null,
                'buried_at' => $this->resolveBuriedAt($sidecar, $storage, $path),
                'context' => is_array($sidecar['context'] ?? null) ? $sidecar['context'] : [],
            ];
        }
    }

    private function resolveBuriedAt(?array $sidecar, $storage, string $path): ?Carbon
    {
        $raw = $sidecar['buried_at'] ?? null;
        if (is_string($raw) && trim($raw) !== '') {
            try {
                return Carbon::parse($raw);
            } catch (\Throwable) {
                // fall through to mtime
            }
        }

        try {
            return Carbon::createFromTimestamp($storage->lastModified($path));
        } catch (\Throwable) {
            return null;
        }
    }

    private function graveyardRoot(): string
    {
        $configured = config('proofgen.graveyard.path');
        $root = is_string($configured) && trim($configured) !== '' ? trim($configured) : '_graveyard';

        return $this->pathResolver->normalizePath($root);
    }
}
