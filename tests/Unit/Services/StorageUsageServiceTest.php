<?php

namespace Tests\Unit\Services;

use App\Models\Show;
use App\Models\ShowClass;
use App\Services\StorageUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function writeBytes(string $relative, int $bytes): void
    {
        $absolute = $this->tempPath.'/'.$relative;
        if (! is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0755, true);
        }
        file_put_contents($absolute, str_repeat('x', $bytes));
    }
}
