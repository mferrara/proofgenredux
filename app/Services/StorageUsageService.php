<?php

namespace App\Services;

use App\Models\Show;
use App\Models\ShowClass;
use Illuminate\Support\Facades\Cache;

/**
 * Measures on-disk storage usage at show, class, and miscellaneous-directory
 * scopes. Walking large image trees can be slow (thousands of files); results
 * are snapshotted.
 *
 * Class/show snapshots are retained forever (no expiring TTL) so a slow or
 * failed refresh never loses the last known numbers. Freshness is derived per
 * read from the snapshot's `measured_at` unix timestamp: a snapshot older than
 * CACHE_TTL_SECONDS is marked `stale => true` but is still returned. Callers
 * can therefore render the last snapshot immediately and let the operator
 * decide when to refresh.
 */
class StorageUsageService
{
    public const CACHE_TTL_SECONDS = 600;

    public const CATEGORIES = ['originals', 'proofs', 'web_images', 'highres_images', 'archive'];

    private const CLASS_CACHE_PREFIX = 'storage_usage:class:';

    private const SHOW_CACHE_PREFIX = 'storage_usage:show:';

    public function __construct(private ?PathResolver $pathResolver = null)
    {
        $this->pathResolver ??= app(PathResolver::class);
    }

    /**
     * Per-class breakdown, re-measured only when no snapshot exists or a
     * refresh is forced. Otherwise the retained snapshot is returned even when
     * it is stale (the `stale` key tells the caller).
     *
     * Returned shape:
     *   ['originals' => ['bytes' => N, 'count' => N], ...,
     *    'archive' => [...], 'total' => ['bytes' => N, 'count' => N],
     *    'measured_at' => int|null, 'stale' => bool]
     */
    public function classUsage(ShowClass $class, bool $forceRefresh = false): array
    {
        if (! $forceRefresh) {
            $cached = $this->cachedClassUsage($class);
            if ($cached !== null) {
                return $cached;
            }
        }

        $snapshot = $this->snapshotClass($class);
        Cache::forever(self::CLASS_CACHE_PREFIX.$class->id, $snapshot);

        return $this->decorate($snapshot);
    }

    /**
     * Read-only access to the last class snapshot. Never scans the filesystem;
     * returns null when nothing has ever been measured.
     */
    public function cachedClassUsage(ShowClass $class): ?array
    {
        return $this->readSnapshot(self::CLASS_CACHE_PREFIX.$class->id);
    }

    /**
     * Show aggregate, re-measured only when no snapshot exists or a refresh is
     * forced. Otherwise the retained snapshot is returned even when stale.
     */
    public function showUsage(Show $show, bool $forceRefresh = false): array
    {
        if (! $forceRefresh) {
            $cached = $this->cachedShowUsage($show);
            if ($cached !== null) {
                return $cached;
            }
        }

        return $this->refreshShow($show);
    }

    /**
     * Read-only access to the last show snapshot. Never scans the filesystem;
     * returns null when nothing has ever been measured.
     */
    public function cachedShowUsage(Show $show): ?array
    {
        return $this->readSnapshot(self::SHOW_CACHE_PREFIX.$show->id);
    }

    /**
     * Recompute every class snapshot plus the show aggregate, then persist them.
     *
     * All measurements happen before any cache write, so a filesystem failure
     * (for example an unmounted NAS or archive drive) leaves the previous class
     * and show snapshots intact instead of replacing them with zeros.
     */
    public function refreshShow(Show $show): array
    {
        $classSnapshots = [];
        $aggregate = $this->emptyAggregate();

        foreach ($show->classes as $class) {
            $snapshot = $this->snapshotClass($class);
            $classSnapshots[$class->id] = $snapshot;

            foreach (self::CATEGORIES as $category) {
                $aggregate[$category]['bytes'] += $snapshot[$category]['bytes'];
                $aggregate[$category]['count'] += $snapshot[$category]['count'];
            }
            $aggregate['total']['bytes'] += $snapshot['total']['bytes'];
            $aggregate['total']['count'] += $snapshot['total']['count'];
        }

        foreach ($classSnapshots as $id => $snapshot) {
            Cache::forever(self::CLASS_CACHE_PREFIX.$id, $snapshot);
        }

        $snapshot = $aggregate + ['measured_at' => now()->timestamp];
        Cache::forever(self::SHOW_CACHE_PREFIX.$show->id, $snapshot);

        return $this->decorate($snapshot);
    }

    /**
     * Sample images directory (storage/sample_images). May be empty if the operator
     * hasn't downloaded the sample set.
     */
    public function sampleImagesUsage(bool $forceRefresh = false): array
    {
        return $this->cachedDirectoryUsage(
            cacheKey: 'storage_usage:sample_images',
            absolutePath: (string) (config('filesystems.disks.sample_images.root') ?: storage_path('sample_images')),
            forceRefresh: $forceRefresh,
        );
    }

    /**
     * Application backups directory (base_path('backups')) — created by UpdateService
     * for in-place updater rollback. Worth surfacing because it can grow to GBs.
     */
    public function backupsUsage(bool $forceRefresh = false): array
    {
        return $this->cachedDirectoryUsage(
            cacheKey: 'storage_usage:backups',
            absolutePath: base_path('backups'),
            forceRefresh: $forceRefresh,
        );
    }

    /**
     * Generic directory measurement for any absolute path. Returns
     * ['bytes' => int, 'count' => int, 'exists' => bool].
     */
    public function directoryUsage(string $absolutePath): array
    {
        if (! is_dir($absolutePath)) {
            return ['bytes' => 0, 'count' => 0, 'exists' => false];
        }

        $bytes = 0;
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absolutePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $bytes += $file->getSize();
                $count++;
            }
        }

        return ['bytes' => $bytes, 'count' => $count, 'exists' => true];
    }

    public static function formatBytes(int $bytes, int $precision = 2): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);

        return round($bytes / (1024 ** $power), $precision).' '.$units[$power];
    }

    private function cachedDirectoryUsage(string $cacheKey, string $absolutePath, bool $forceRefresh): array
    {
        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($absolutePath) {
            return $this->directoryUsage($absolutePath);
        });
    }

    /**
     * Read a retained snapshot and normalise it for callers. Never scans.
     *
     * Legacy snapshots (written before `measured_at` existed, or by the old
     * expiring Cache::remember path) are preserved and re-persisted forever so
     * their old TTL can no longer evict them. Their `measured_at` stays null.
     */
    private function readSnapshot(string $cacheKey): ?array
    {
        $snapshot = Cache::get($cacheKey);
        if (! is_array($snapshot)) {
            return null;
        }

        if (! array_key_exists('measured_at', $snapshot)) {
            $snapshot['measured_at'] = null;
            unset($snapshot['stale']);
            Cache::forever($cacheKey, $snapshot);
        }

        return $this->decorate($snapshot);
    }

    /**
     * Add the derived `stale` flag to a stored snapshot. `stale` is computed on
     * every read and is never persisted.
     */
    private function decorate(array $snapshot): array
    {
        $measuredAt = $snapshot['measured_at'] ?? null;
        $snapshot['measured_at'] = $measuredAt;
        $snapshot['stale'] = $measuredAt === null
            || (now()->timestamp - (int) $measuredAt) >= self::CACHE_TTL_SECONDS;

        return $snapshot;
    }

    private function snapshotClass(ShowClass $class): array
    {
        return $this->measureClass($class->show_id, $class->name) + ['measured_at' => now()->timestamp];
    }

    private function emptyAggregate(): array
    {
        $aggregate = array_fill_keys(self::CATEGORIES, ['bytes' => 0, 'count' => 0]);
        $aggregate['total'] = ['bytes' => 0, 'count' => 0];

        return $aggregate;
    }

    private function measureClass(string $showId, string $className): array
    {
        $fullsizeRoot = rtrim((string) config('proofgen.fullsize_home_dir'), '/');
        $archiveRoot = rtrim((string) config('proofgen.archive_home_dir'), '/');

        // Missing roots (unmounted NAS / external archive drive) are refused
        // rather than measured as zero. An existing but empty root still
        // measures normally (all zeros), so "empty" and "unavailable" are
        // distinguishable. Unconfigured roots (empty string) are left as zero.
        if ($fullsizeRoot !== '' && ! is_dir($fullsizeRoot)) {
            throw new \RuntimeException("Storage usage aborted: fullsize root unavailable at {$fullsizeRoot}");
        }
        if (config('proofgen.archive_enabled') && $archiveRoot !== '' && ! is_dir($archiveRoot)) {
            throw new \RuntimeException("Storage usage aborted: archive root unavailable at {$archiveRoot}");
        }

        $paths = [
            'originals' => $fullsizeRoot === '' ? null : $fullsizeRoot.'/'.$showId.'/'.$className.'/originals',
            'proofs' => $fullsizeRoot === '' ? null : $fullsizeRoot.'/proofs/'.$showId.'/'.$className,
            'web_images' => $fullsizeRoot === '' ? null : $fullsizeRoot.'/web_images/'.$showId.'/'.$className,
            'highres_images' => $fullsizeRoot === '' ? null : $fullsizeRoot.'/highres_images/'.$showId.'/'.$className,
            'archive' => $archiveRoot === '' ? null : $archiveRoot.'/'.$showId.'/'.$className,
        ];

        $usage = [];
        $totalBytes = 0;
        $totalCount = 0;
        foreach ($paths as $category => $path) {
            $measurement = $path === null
                ? ['bytes' => 0, 'count' => 0, 'exists' => false]
                : $this->directoryUsage($path);
            $usage[$category] = ['bytes' => $measurement['bytes'], 'count' => $measurement['count']];
            $totalBytes += $measurement['bytes'];
            $totalCount += $measurement['count'];
        }
        $usage['total'] = ['bytes' => $totalBytes, 'count' => $totalCount];

        return $usage;
    }
}
