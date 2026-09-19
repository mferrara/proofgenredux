<?php

namespace App\Services;

use App\Models\Photo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

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

    private const REDUNDANT_CACHE_KEY = 'graveyard.redundant-import-copies';

    /**
     * Camera files buried after a successful import, on the working disk, whose
     * photo is provably held elsewhere: the imported original is on disk at the
     * same size under the same content hash, and with Backups on the archive
     * copy was recorded too. These are a second full-size copy of every photo
     * of a show, on the disk that fills up first.
     *
     * Cached because the status bar asks every few seconds.
     *
     * @return array{count:int, bytes:int}
     */
    public function redundantImportCopies(): array
    {
        return Cache::remember(self::REDUNDANT_CACHE_KEY, 300, function () {
            $count = 0;
            $bytes = 0;
            foreach ($this->redundantEntries() as $entry) {
                $count++;
                $bytes += $entry['size'];
            }

            return ['count' => $count, 'bytes' => $bytes];
        });
    }

    /**
     * Delete the redundant copies. Each imported original is read back and
     * hashed first; a copy whose original does not match stays in the graveyard.
     * With purgeOlderThan(), the only place graveyard files are deleted.
     *
     * @return array{deleted_count:int, freed_bytes:int, kept_count:int}
     */
    public function removeRedundantImportCopies(): array
    {
        $storage = Storage::disk('fullsize');
        $result = ['deleted_count' => 0, 'freed_bytes' => 0, 'kept_count' => 0];

        foreach ($this->redundantEntries() as $entry) {
            $stream = $storage->readStream($entry['photo']->relative_path);
            $hash = hash_init('sha1');
            hash_update_stream($hash, $stream);
            fclose($stream);

            if (hash_final($hash) !== $entry['sha1']) {
                Log::warning('Graveyard copy kept: the imported original no longer matches it.', ['graveyard_path' => $entry['graveyard_path'], 'photo' => $entry['photo']->id]);
                $result['kept_count']++;

                continue;
            }

            $storage->delete($entry['graveyard_path']);
            if ($entry['sidecar_path'] !== null) {
                $storage->delete($entry['sidecar_path']);
            }
            $result['deleted_count']++;
            $result['freed_bytes'] += $entry['size'];
        }

        Cache::forget(self::REDUNDANT_CACHE_KEY);
        Log::info('Removed redundant graveyard copies.', $result);

        return $result;
    }

    /**
     * @return iterable<int, array{graveyard_path:string, sidecar_path:?string, sha1:string, size:int, photo:Photo}>
     */
    private function redundantEntries(): iterable
    {
        $storage = Storage::disk('fullsize');
        $archiveRequired = (bool) config('proofgen.archive_enabled');

        foreach ($this->dataFiles('fullsize') as $entry) {
            if ($entry['reason'] !== SafeFileMover::REASON_POST_IMPORT_SOURCE || $entry['sha1'] === null) {
                continue;
            }

            $photo = Photo::find($entry['context']['photo_id'] ?? null) ?? Photo::where('sha1', $entry['sha1'])->first();
            if (! $photo || $photo->sha1 !== $entry['sha1']) {
                continue;
            }

            if ($archiveRequired && $photo->archive_sha1 !== $entry['sha1']) {
                continue;
            }

            $original = $photo->relative_path;
            if (! $storage->exists($original) || $storage->size($original) !== $entry['size']) {
                continue;
            }

            yield ['photo' => $photo] + $entry;
        }
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
        $root = $this->graveyardRoot();

        // The archive usually lives on an external drive. When it is not
        // plugged in, building the disk throws (its root cannot be created);
        // that must read as "nothing there right now", not take down every
        // page through the status bar.
        try {
            $storage = Storage::disk($disk);

            if (! $storage->exists($root)) {
                return;
            }
        } catch (Throwable $exception) {
            Log::debug('Graveyard disk unavailable; skipping.', ['disk' => $disk, 'reason' => $exception->getMessage()]);

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
            } catch (Throwable) {
                // fall through to mtime
            }
        }

        try {
            return Carbon::createFromTimestamp($storage->lastModified($path));
        } catch (Throwable) {
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
