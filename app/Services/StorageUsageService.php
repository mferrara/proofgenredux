<?php

namespace App\Services;

use App\Models\Show;
use App\Models\ShowClass;
use Illuminate\Support\Facades\Cache;

/**
 * Measures on-disk storage usage at show, class, and miscellaneous-directory
 * scopes. Walking large image trees can be slow (thousands of files); results
 * are cached by default and operators can refresh on demand.
 */
class StorageUsageService
{
    public const CACHE_TTL_SECONDS = 600;

    public const CATEGORIES = ['originals', 'proofs', 'web_images', 'highres_images', 'archive'];

    public function __construct(private ?PathResolver $pathResolver = null)
    {
        $this->pathResolver ??= app(PathResolver::class);
    }

    /**
     * Per-class breakdown:
     *   ['originals' => ['bytes' => N, 'count' => N], 'proofs' => [...], ...,
     *    'archive' => [...], 'total' => ['bytes' => N, 'count' => N]]
     */
    public function classUsage(ShowClass $class, bool $forceRefresh = false): array
    {
        $cacheKey = 'storage_usage:class:'.$class->id;
        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($class) {
            return $this->measureClass($class->show_id, $class->name);
        });
    }

    /**
     * Per-show aggregate by walking each ShowClass once. Cached separately so
     * "refresh show" doesn't re-walk every class — call refreshShow() to do that.
     */
    public function showUsage(Show $show, bool $forceRefresh = false): array
    {
        $cacheKey = 'storage_usage:show:'.$show->id;
        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($show) {
            $aggregate = array_fill_keys(self::CATEGORIES, ['bytes' => 0, 'count' => 0]);
            $aggregate['total'] = ['bytes' => 0, 'count' => 0];

            foreach ($show->classes as $class) {
                $usage = $this->measureClass($show->id, $class->name);
                foreach ($aggregate as $category => $_) {
                    $aggregate[$category]['bytes'] += $usage[$category]['bytes'];
                    $aggregate[$category]['count'] += $usage[$category]['count'];
                }
            }

            return $aggregate;
        });
    }

    public function refreshShow(Show $show): array
    {
        Cache::forget('storage_usage:show:'.$show->id);
        foreach ($show->classes as $class) {
            Cache::forget('storage_usage:class:'.$class->id);
        }

        return $this->showUsage($show);
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

    private function measureClass(string $showId, string $className): array
    {
        $fullsizeRoot = rtrim((string) config('proofgen.fullsize_home_dir'), '/');
        $archiveRoot = rtrim((string) config('proofgen.archive_home_dir'), '/');

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
