<?php

namespace Tests\Unit\Services;

use App\Models\Show;
use App\Models\ShowClass;
use App\Services\StorageUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class StorageUsageServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        config(['testing.skip_file_operations' => true]);

        $this->tempPath = storage_path('app/storage_usage_test_'.uniqid());
        File::makeDirectory($this->tempPath.'/fullsize', 0755, true);
        File::makeDirectory($this->tempPath.'/archive', 0755, true);

        config(['proofgen.fullsize_home_dir' => $this->tempPath.'/fullsize']);
        config(['proofgen.archive_home_dir' => $this->tempPath.'/archive']);

        Cache::flush();

        Show::withoutEvents(function () {
            Show::create(['id' => 'SHOW1', 'name' => 'SHOW1']);
        });
        ShowClass::withoutEvents(function () {
            ShowClass::create(['id' => 'SHOW1_101', 'show_id' => 'SHOW1', 'name' => '101']);
            ShowClass::create(['id' => 'SHOW1_102', 'show_id' => 'SHOW1', 'name' => '102']);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if (isset($this->tempPath) && File::exists($this->tempPath)) {
            File::deleteDirectory($this->tempPath);
        }
        parent::tearDown();
    }

    public function test_class_usage_measures_each_category_and_totals(): void
    {
        $this->writeBytes('fullsize/SHOW1/101/originals/00001.jpg', 100);
        $this->writeBytes('fullsize/SHOW1/101/originals/00002.jpg', 200);
        $this->writeBytes('fullsize/proofs/SHOW1/101/00001_thm.jpg', 50);
        $this->writeBytes('fullsize/web_images/SHOW1/101/00001_web.jpg', 75);
        $this->writeBytes('fullsize/highres_images/SHOW1/101/00001_highres.jpg', 150);
        $this->writeBytes('archive/SHOW1/101/00001.jpg', 100);

        $class = ShowClass::find('SHOW1_101');
        $usage = app(StorageUsageService::class)->classUsage($class);

        $this->assertSame(300, $usage['originals']['bytes']);
        $this->assertSame(2, $usage['originals']['count']);
        $this->assertSame(50, $usage['proofs']['bytes']);
        $this->assertSame(75, $usage['web_images']['bytes']);
        $this->assertSame(150, $usage['highres_images']['bytes']);
        $this->assertSame(100, $usage['archive']['bytes']);
        $this->assertSame(300 + 50 + 75 + 150 + 100, $usage['total']['bytes']);
        $this->assertSame(6, $usage['total']['count']);

        // Freshly measured snapshot carries the metadata.
        $this->assertIsInt($usage['measured_at']);
        $this->assertFalse($usage['stale']);
    }

    public function test_class_usage_returns_zeros_for_classes_with_no_files(): void
    {
        $class = ShowClass::find('SHOW1_102');
        $usage = app(StorageUsageService::class)->classUsage($class);

        foreach (StorageUsageService::CATEGORIES as $category) {
            $this->assertSame(0, $usage[$category]['bytes']);
            $this->assertSame(0, $usage[$category]['count']);
        }
        $this->assertSame(0, $usage['total']['bytes']);

        // Empty-but-present roots are valid and distinguishable from missing roots.
        $this->assertDirectoryExists($this->tempPath.'/fullsize');
        $this->assertFalse($usage['stale']);
    }

    public function test_show_usage_aggregates_classes(): void
    {
        $this->writeBytes('fullsize/SHOW1/101/originals/00001.jpg', 100);
        $this->writeBytes('fullsize/SHOW1/102/originals/00001.jpg', 250);
        $this->writeBytes('archive/SHOW1/101/00001.jpg', 100);
        $this->writeBytes('archive/SHOW1/102/00001.jpg', 250);

        $show = Show::find('SHOW1');
        $usage = app(StorageUsageService::class)->showUsage($show);

        $this->assertSame(350, $usage['originals']['bytes']);
        $this->assertSame(2, $usage['originals']['count']);
        $this->assertSame(350, $usage['archive']['bytes']);
        $this->assertSame(700, $usage['total']['bytes']);
        $this->assertIsInt($usage['measured_at']);
        $this->assertFalse($usage['stale']);
    }

    public function test_results_are_cached_until_force_refresh(): void
    {
        $this->writeBytes('fullsize/SHOW1/101/originals/00001.jpg', 100);
        $class = ShowClass::find('SHOW1_101');
        $service = app(StorageUsageService::class);

        $first = $service->classUsage($class);
        $this->assertSame(100, $first['originals']['bytes']);

        // Add a file; cached value should still return the original total.
        $this->writeBytes('fullsize/SHOW1/101/originals/00002.jpg', 200);
        $cached = $service->classUsage($class);
        $this->assertSame(100, $cached['originals']['bytes']);

        $refreshed = $service->classUsage($class, forceRefresh: true);
        $this->assertSame(300, $refreshed['originals']['bytes']);
    }

    public function test_snapshot_survives_beyond_ttl_and_is_marked_stale(): void
    {
        $this->writeBytes('fullsize/SHOW1/101/originals/00001.jpg', 100);
        $class = ShowClass::find('SHOW1_101');
        $service = app(StorageUsageService::class);

        $fresh = $service->classUsage($class);
        $this->assertFalse($fresh['stale']);

        $this->advanceSeconds(700);

        // Cache::forever means the snapshot is still readable past the old TTL.
        $cached = $service->cachedClassUsage($class);
        $this->assertNotNull($cached);
        $this->assertSame(100, $cached['originals']['bytes']);
        $this->assertSame($fresh['measured_at'], $cached['measured_at']);
        $this->assertTrue($cached['stale']);

        // classUsage keeps returning the retained snapshot (no rescan, no zeroing).
        $this->writeBytes('fullsize/SHOW1/101/originals/00002.jpg', 200);
        $stillCached = $service->classUsage($class);
        $this->assertSame(100, $stillCached['originals']['bytes']);
        $this->assertTrue($stillCached['stale']);
    }

    public function test_stale_flag_flips_after_ttl_seconds(): void
    {
        $this->writeBytes('fullsize/SHOW1/101/originals/00001.jpg', 100);
        $class = ShowClass::find('SHOW1_101');
        $service = app(StorageUsageService::class);

        $service->classUsage($class);

        $this->advanceSeconds(599);
        $this->assertFalse($service->cachedClassUsage($class)['stale']);

        $this->advanceSeconds(1);
        $this->assertTrue($service->cachedClassUsage($class)['stale']);
    }

    public function test_cache_only_miss_returns_null_without_scanning(): void
    {
        // Remove the roots: if a read scanned, measureClass() would throw.
        File::deleteDirectory($this->tempPath.'/fullsize');
        File::deleteDirectory($this->tempPath.'/archive');

        $service = app(StorageUsageService::class);
        $class = ShowClass::find('SHOW1_101');
        $show = Show::find('SHOW1');

        $this->assertNull($service->cachedClassUsage($class));
        $this->assertNull($service->cachedShowUsage($show));
    }

    public function test_force_refresh_updates_counts_and_measured_at(): void
    {
        $this->writeBytes('fullsize/SHOW1/101/originals/00001.jpg', 100);
        $class = ShowClass::find('SHOW1_101');
        $service = app(StorageUsageService::class);

        $first = $service->classUsage($class);
        $this->assertSame(1, $first['originals']['count']);

        $this->advanceSeconds(5);
        $this->writeBytes('fullsize/SHOW1/101/originals/00002.jpg', 200);

        $refreshed = $service->classUsage($class, forceRefresh: true);
        $this->assertSame(300, $refreshed['originals']['bytes']);
        $this->assertSame(2, $refreshed['originals']['count']);
        $this->assertGreaterThan($first['measured_at'], $refreshed['measured_at']);
        $this->assertFalse($refreshed['stale']);

        $persisted = $service->cachedClassUsage($class);
        $this->assertSame(2, $persisted['originals']['count']);
        $this->assertSame($refreshed['measured_at'], $persisted['measured_at']);
    }

    public function test_refresh_show_recomputes_class_snapshots_too(): void
    {
        $this->writeBytes('fullsize/SHOW1/101/originals/00001.jpg', 100);
        $show = Show::find('SHOW1');
        $service = app(StorageUsageService::class);

        $first = $service->showUsage($show);
        $this->assertSame(100, $first['originals']['bytes']);
        $this->assertSame(100, $service->cachedClassUsage(ShowClass::find('SHOW1_101'))['originals']['bytes']);

        $this->writeBytes('fullsize/SHOW1/101/originals/00002.jpg', 200);

        $refreshed = $service->refreshShow($show);
        $this->assertSame(300, $refreshed['originals']['bytes']);
        $this->assertFalse($refreshed['stale']);

        $refreshedClass = $service->cachedClassUsage(ShowClass::find('SHOW1_101'));
        $this->assertSame(300, $refreshedClass['originals']['bytes']);
        $this->assertSame($refreshed['measured_at'], $refreshedClass['measured_at']);
    }

    public function test_legacy_cached_stats_are_retained_and_marked_stale(): void
    {
        Cache::put('storage_usage:class:SHOW1_101', $this->legacySnapshot(123), 600);

        $service = app(StorageUsageService::class);
        $class = ShowClass::find('SHOW1_101');

        $cached = $service->cachedClassUsage($class);
        $this->assertNotNull($cached);
        $this->assertSame(123, $cached['originals']['bytes']);
        $this->assertNull($cached['measured_at']);
        $this->assertTrue($cached['stale']);

        // The read re-persisted it forever, so the old 600s TTL no longer evicts it.
        $this->advanceSeconds(700);

        // classUsage serves the retained legacy snapshot without scanning.
        File::deleteDirectory($this->tempPath.'/fullsize');
        File::deleteDirectory($this->tempPath.'/archive');

        $again = $service->classUsage($class);
        $this->assertSame(123, $again['originals']['bytes']);
        $this->assertNull($again['measured_at']);
        $this->assertTrue($again['stale']);
    }

    public function test_throwing_refresh_preserves_last_snapshot(): void
    {
        $this->writeBytes('fullsize/SHOW1/101/originals/00001.jpg', 100);
        $class = ShowClass::find('SHOW1_101');
        $show = Show::find('SHOW1');
        $service = app(StorageUsageService::class);

        $service->classUsage($class);
        $service->refreshShow($show);

        // NAS/archive root disappears (unmounted).
        File::deleteDirectory($this->tempPath.'/fullsize');
        File::deleteDirectory($this->tempPath.'/archive');

        try {
            $service->classUsage($class, forceRefresh: true);
            $this->fail('Expected a RuntimeException when the storage root is unavailable.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('unavailable', $e->getMessage());
        }

        $preservedClass = $service->cachedClassUsage($class);
        $this->assertSame(100, $preservedClass['originals']['bytes']);

        try {
            $service->refreshShow($show);
            $this->fail('Expected a RuntimeException when refreshing a show with an unavailable root.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('unavailable', $e->getMessage());
        }

        $preservedShow = $service->cachedShowUsage($show);
        $this->assertNotNull($preservedShow);
        $this->assertSame(100, $preservedShow['originals']['bytes']);
        $this->assertSame(100, $preservedShow['total']['bytes']);
    }

    public function test_show_usage_returns_stale_snapshot_instead_of_rescanning(): void
    {
        $this->writeBytes('fullsize/SHOW1/101/originals/00001.jpg', 100);
        $show = Show::find('SHOW1');
        $service = app(StorageUsageService::class);

        $first = $service->showUsage($show);
        $this->advanceSeconds(700);

        File::deleteDirectory($this->tempPath.'/fullsize');

        // Root is gone, but the retained show snapshot is returned (no throw).
        $cached = $service->showUsage($show);
        $this->assertSame(100, $cached['originals']['bytes']);
        $this->assertTrue($cached['stale']);
        $this->assertSame($first['measured_at'], $cached['measured_at']);
    }

    public function test_directory_usage_handles_missing_path(): void
    {
        $usage = app(StorageUsageService::class)->directoryUsage($this->tempPath.'/does-not-exist');
        $this->assertFalse($usage['exists']);
        $this->assertSame(0, $usage['bytes']);
    }

    public function test_format_bytes_renders_human_units(): void
    {
        $this->assertSame('0 B', StorageUsageService::formatBytes(0));
        $this->assertSame('500 B', StorageUsageService::formatBytes(500));
        $this->assertSame('1 KB', StorageUsageService::formatBytes(1024));
        $this->assertSame('1.5 MB', StorageUsageService::formatBytes((int) (1.5 * 1024 * 1024)));
        $this->assertSame('2 GB', StorageUsageService::formatBytes(2 * 1024 ** 3));
    }

    private function advanceSeconds(int $seconds): void
    {
        Carbon::setTestNow(Carbon::now()->addSeconds($seconds));
    }

    private function legacySnapshot(int $originalsBytes): array
    {
        $snapshot = array_fill_keys(StorageUsageService::CATEGORIES, ['bytes' => 0, 'count' => 0]);
        $snapshot['originals'] = ['bytes' => $originalsBytes, 'count' => 1];
        $snapshot['total'] = ['bytes' => $originalsBytes, 'count' => 1];

        return $snapshot;
    }

    private function writeBytes(string $relative, int $bytes): void
    {
        $absolute = $this->tempPath.'/'.$relative;
        if (! is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0755, true);
        }
        file_put_contents($absolute, str_repeat('x', $bytes));
    }
}
