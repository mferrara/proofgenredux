<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class SafeFileMover
{
    public const REASON_POST_IMPORT_SOURCE = 'post_import_source';

    public const REASON_PHOTO_DELETED = 'photo_deleted';

    public const REASON_DISPLACED_ORIGINAL = 'displaced_original';

    public const REASON_REDUNDANT_ARCHIVE_SOURCE = 'redundant_archive_source';

    public const REASON_IMPORT_CONFLICT = 'import_conflict';

    private const SIDECAR_EXTENSION = '.json';

    private const IMPORT_CONFLICT_DIRECTORY = '_import_conflicts';

    public function __construct(private ?PathResolver $pathResolver = null)
    {
        $this->pathResolver ??= app(PathResolver::class);
    }

    /**
     * Move a file on $disk into the graveyard alongside a JSON sidecar.
     * Returns metadata describing the buried file.
     */
    public function bury(string $disk, string $path, string $reason, array $context = []): array
    {
        $relative = $this->pathResolver->normalizePath($path);
        $storage = Storage::disk($disk);

        if (! $storage->exists($relative)) {
            throw new RuntimeException("Cannot bury missing file on disk [{$disk}]: {$relative}");
        }

        $sha1 = $context['sha1'] ?? null;
        $size = $context['size'] ?? null;
        if ($sha1 === null || $size === null) {
            $contents = $storage->get($relative);
            $sha1 ??= sha1($contents);
            $size ??= strlen($contents);
        }

        $now = Carbon::now();
        $graveyardPath = $this->resolveAvailableGraveyardPath(
            $disk,
            $this->buildGraveyardPath($relative, $sha1, $now),
        );
        $directory = $this->directoryFor($graveyardPath);
        if ($directory !== '') {
            $storage->makeDirectory($directory);
        }

        $storage->move($relative, $graveyardPath);

        if (! $storage->exists($graveyardPath)) {
            throw new RuntimeException("Failed to move file to graveyard: {$graveyardPath}");
        }

        $sidecarPath = $graveyardPath.self::SIDECAR_EXTENSION;
        $storage->put($sidecarPath, $this->buildSidecarPayload(
            disk: $disk,
            originalPath: $relative,
            sha1: $sha1,
            size: $size,
            reason: $reason,
            context: $context,
            buriedAt: $now,
        ));

        return [
            'disk' => $disk,
            'graveyard_path' => $graveyardPath,
            'sidecar_path' => $sidecarPath,
            'sha1' => $sha1,
            'size' => $size,
            'reason' => $reason,
            'buried_at' => $now->toIso8601String(),
        ];
    }

    /**
     * Move an unresolved import source into the per-class _import_conflicts directory.
     * The file stays visible alongside the class it was being imported into so an
     * operator browsing in Finder sees it next to the conflict it represents. Same
     * sidecar format as bury(), different layout.
     */
    public function quarantineImport(string $disk, string $sourcePath, string $show, string $class, array $context = []): array
    {
        $relative = $this->pathResolver->normalizePath($sourcePath);
        $storage = Storage::disk($disk);

        if (! $storage->exists($relative)) {
            throw new RuntimeException("Cannot quarantine missing file on disk [{$disk}]: {$relative}");
        }

        $sha1 = $context['sha1'] ?? null;
        $size = $context['size'] ?? null;
        if ($sha1 === null || $size === null) {
            $contents = $storage->get($relative);
            $sha1 ??= sha1($contents);
            $size ??= strlen($contents);
        }

        $now = Carbon::now();
        $candidate = $this->buildImportConflictPath($show, $class, $relative, $sha1, $now);
        $target = $this->resolveAvailableGraveyardPath($disk, $candidate);
        $directory = $this->directoryFor($target);
        if ($directory !== '') {
            $storage->makeDirectory($directory);
        }

        $storage->move($relative, $target);

        if (! $storage->exists($target)) {
            throw new RuntimeException("Failed to quarantine file: {$target}");
        }

        $sidecarPath = $target.self::SIDECAR_EXTENSION;
        $storage->put($sidecarPath, $this->buildSidecarPayload(
            disk: $disk,
            originalPath: $relative,
            sha1: $sha1,
            size: $size,
            reason: self::REASON_IMPORT_CONFLICT,
            context: $context,
            buriedAt: $now,
        ));

        return [
            'disk' => $disk,
            'quarantine_path' => $target,
            'sidecar_path' => $sidecarPath,
            'sha1' => $sha1,
            'size' => $size,
            'reason' => self::REASON_IMPORT_CONFLICT,
            'buried_at' => $now->toIso8601String(),
        ];
    }

    private function buildImportConflictPath(string $show, string $class, string $sourceRelative, string $sha1, Carbon $when): string
    {
        $basename = basename($sourceRelative);
        $extension = pathinfo($basename, PATHINFO_EXTENSION);
        $stem = pathinfo($basename, PATHINFO_FILENAME);
        $stamp = $when->format('Ymd-His');
        $shortSha = substr($sha1, 0, 8);
        $suffix = $stem.'_'.$stamp.'_'.$shortSha.($extension !== '' ? '.'.$extension : '');

        return $this->pathResolver->normalizePath(
            $show.'/'.$class.'/'.self::IMPORT_CONFLICT_DIRECTORY.'/'.$suffix
        );
    }

    /**
     * Convenience for callers that have an absolute path. Resolves to the matching
     * configured disk and delegates to bury(). Currently considers fullsize + archive.
     */
    public function buryAbsolute(string $absolutePath, string $reason, array $context = []): array
    {
        $absolutePath = rtrim(str_replace('\\', '/', $absolutePath), '/');

        foreach (['fullsize' => 'fullsize_home_dir', 'archive' => 'archive_home_dir'] as $disk => $configKey) {
            $root = config("filesystems.disks.{$disk}.root") ?? config("proofgen.{$configKey}");
            if (! is_string($root) || trim($root) === '') {
                continue;
            }
            $root = rtrim(str_replace('\\', '/', $root), '/');
            if ($absolutePath === $root || str_starts_with($absolutePath.'/', $root.'/')) {
                $relative = ltrim(substr($absolutePath, strlen($root)), '/');

                return $this->bury($disk, $relative, $reason, $context);
            }
        }

        throw new RuntimeException("Absolute path is not within any known disk root: {$absolutePath}");
    }

    private function buildGraveyardPath(string $sourceRelative, string $sha1, Carbon $when): string
    {
        $root = $this->graveyardRoot();
        $datePartition = $when->format('Y-m-d');
        $directory = $this->directoryFor($sourceRelative);
        $basename = basename($sourceRelative);
        $extension = pathinfo($basename, PATHINFO_EXTENSION);
        $stem = pathinfo($basename, PATHINFO_FILENAME);

        $shortSha = substr($sha1, 0, 8);
        $stamp = $when->format('Ymd-His');
        $suffix = $stem.'_'.$stamp.'_'.$shortSha.($extension !== '' ? '.'.$extension : '');

        $segments = array_filter([$root, $datePartition, $directory, $suffix], fn ($s) => $s !== '' && $s !== null);

        return $this->pathResolver->normalizePath(implode('/', $segments));
    }

    private function resolveAvailableGraveyardPath(string $disk, string $candidate): string
    {
        $storage = Storage::disk($disk);
        if (! $storage->exists($candidate) && ! $storage->exists($candidate.self::SIDECAR_EXTENSION)) {
            return $candidate;
        }

        $extension = pathinfo($candidate, PATHINFO_EXTENSION);
        $base = $extension !== '' ? substr($candidate, 0, -1 - strlen($extension)) : $candidate;
        $i = 1;
        do {
            $next = $base.'_'.$i.($extension !== '' ? '.'.$extension : '');
            $i++;
        } while ($storage->exists($next) || $storage->exists($next.self::SIDECAR_EXTENSION));

        return $next;
    }

    private function buildSidecarPayload(
        string $disk,
        string $originalPath,
        string $sha1,
        int $size,
        string $reason,
        array $context,
        Carbon $buriedAt,
    ): string {
        $payload = [
            'schema' => 'proofgen.graveyard.v1',
            'disk' => $disk,
            'original_path' => $originalPath,
            'sha1' => $sha1,
            'size' => $size,
            'reason' => $reason,
            'buried_at' => $buriedAt->toIso8601String(),
            'context' => $this->cleanContext($context),
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function cleanContext(array $context): array
    {
        unset($context['sha1'], $context['size']);

        return $context;
    }

    private function graveyardRoot(): string
    {
        $configured = config('proofgen.graveyard.path');
        $root = is_string($configured) && trim($configured) !== '' ? trim($configured) : '_graveyard';

        return $this->pathResolver->normalizePath($root);
    }

    private function directoryFor(string $path): string
    {
        $directory = dirname($path);

        return $directory === '.' ? '' : $this->pathResolver->normalizePath($directory);
    }
}
