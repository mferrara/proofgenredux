<?php

namespace Tests\Unit\Services;

use App\Services\GraveyardService;
use App\Services\SafeFileMover;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GraveyardServiceTest extends TestCase
{
    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempPath = storage_path('app/graveyard_service_test_'.uniqid());
        File::makeDirectory($this->tempPath.'/fullsize', 0755, true);
        File::makeDirectory($this->tempPath.'/archive', 0755, true);

        config(['proofgen.fullsize_home_dir' => $this->tempPath.'/fullsize']);
        config(['proofgen.archive_home_dir' => $this->tempPath.'/archive']);
        config(['filesystems.disks.fullsize' => [
            'driver' => 'local',
            'root' => $this->tempPath.'/fullsize',
            'throw' => true,
        ]]);
        config(['filesystems.disks.archive' => [
            'driver' => 'local',
            'root' => $this->tempPath.'/archive',
            'throw' => true,
        ]]);
        Storage::forgetDisk('fullsize');
        Storage::forgetDisk('archive');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if (isset($this->tempPath) && File::exists($this->tempPath)) {
            File::deleteDirectory($this->tempPath);
        }

        parent::tearDown();
    }

    private function buryAt(string $disk, string $path, string $contents, string $when, string $reason = SafeFileMover::REASON_POST_IMPORT_SOURCE): array
    {
        Carbon::setTestNow($when);
        Storage::disk($disk)->put($path, $contents);

        return app(SafeFileMover::class)->bury(
            disk: $disk,
            path: $path,
            reason: $reason,
        );
    }

    public function test_summary_reports_counts_sizes_and_aged_files_per_disk(): void
    {
        $this->buryAt('fullsize', 'SHOW1/101/old.jpg', 'old-bytes', '2026-01-01 12:00:00');
        $this->buryAt('fullsize', 'SHOW1/101/recent.jpg', 'recent-bytes', '2026-05-01 12:00:00');
        $this->buryAt('archive', 'SHOW1/101/lone.jpg', 'a', '2026-04-15 09:00:00');

        Carbon::setTestNow('2026-05-09 12:00:00');

        $summary = app(GraveyardService::class)->summary();

        $this->assertSame(2, $summary['fullsize']['file_count']);
        $this->assertSame(strlen('old-bytes') + strlen('recent-bytes'), $summary['fullsize']['total_bytes']);
        $this->assertSame(1, $summary['fullsize']['aged_file_count']);
        $this->assertNotNull($summary['fullsize']['oldest_buried_at']);
        $this->assertSame('2026-01-01', $summary['fullsize']['oldest_buried_at']->format('Y-m-d'));

        $this->assertSame(1, $summary['archive']['file_count']);
        $this->assertSame(0, $summary['archive']['aged_file_count']);
    }

    public function test_summary_uses_configured_aged_days(): void
    {
        config(['proofgen.graveyard.aged_days' => 7]);

        $this->buryAt('fullsize', 'SHOW1/101/img.jpg', 'bytes', '2026-04-30 12:00:00');

        Carbon::setTestNow('2026-05-09 12:00:00');

        $summary = app(GraveyardService::class)->summary();
        $this->assertSame(1, $summary['fullsize']['aged_file_count']);
    }

    public function test_list_entries_returns_oldest_first_with_sidecar_metadata(): void
    {
        $newer = $this->buryAt('fullsize', 'SHOW1/101/b.jpg', 'newer', '2026-05-01 10:00:00');
        $older = $this->buryAt('fullsize', 'SHOW1/101/a.jpg', 'older', '2026-01-01 10:00:00');

        $entries = app(GraveyardService::class)->listEntries('fullsize');

        $this->assertCount(2, $entries);
        $this->assertSame('SHOW1/101/a.jpg', $entries[0]['original_path']);
        $this->assertSame('SHOW1/101/b.jpg', $entries[1]['original_path']);
        $this->assertSame(sha1('older'), $entries[0]['sha1']);
        $this->assertSame(strlen('older'), $entries[0]['size']);
        $this->assertSame(SafeFileMover::REASON_POST_IMPORT_SOURCE, $entries[0]['reason']);
        $this->assertSame($older['graveyard_path'], $entries[0]['graveyard_path']);
        $this->assertSame($newer['graveyard_path'], $entries[1]['graveyard_path']);
    }

    public function test_list_entries_respects_limit_and_offset(): void
    {
        $this->buryAt('fullsize', 'SHOW1/101/a.jpg', 'a', '2026-01-01 10:00:00');
        $this->buryAt('fullsize', 'SHOW1/101/b.jpg', 'b', '2026-02-01 10:00:00');
        $this->buryAt('fullsize', 'SHOW1/101/c.jpg', 'c', '2026-03-01 10:00:00');

        $page1 = app(GraveyardService::class)->listEntries('fullsize', limit: 2, offset: 0);
        $page2 = app(GraveyardService::class)->listEntries('fullsize', limit: 2, offset: 2);

        $this->assertCount(2, $page1);
        $this->assertCount(1, $page2);
        $this->assertSame('SHOW1/101/a.jpg', $page1[0]['original_path']);
        $this->assertSame('SHOW1/101/c.jpg', $page2[0]['original_path']);
    }

    public function test_purge_older_than_deletes_files_and_sidecars_and_returns_freed_bytes(): void
    {
        $old = $this->buryAt('fullsize', 'SHOW1/101/old.jpg', 'old-bytes', '2026-01-01 10:00:00');
        $recent = $this->buryAt('fullsize', 'SHOW1/101/recent.jpg', 'recent-bytes', '2026-05-01 10:00:00');

        Carbon::setTestNow('2026-05-09 12:00:00');

        $result = app(GraveyardService::class)->purgeOlderThan('fullsize', 90);

        $this->assertSame(1, $result['deleted_count']);
        $this->assertSame(strlen('old-bytes'), $result['freed_bytes']);

        $this->assertFalse(Storage::disk('fullsize')->exists($old['graveyard_path']));
        $this->assertFalse(Storage::disk('fullsize')->exists($old['sidecar_path']));
        $this->assertTrue(Storage::disk('fullsize')->exists($recent['graveyard_path']));
        $this->assertTrue(Storage::disk('fullsize')->exists($recent['sidecar_path']));
    }

    public function test_purge_returns_zero_when_nothing_aged(): void
    {
        $this->buryAt('fullsize', 'SHOW1/101/recent.jpg', 'recent', '2026-05-01 10:00:00');

        Carbon::setTestNow('2026-05-09 12:00:00');

        $result = app(GraveyardService::class)->purgeOlderThan('fullsize', 90);

        $this->assertSame(0, $result['deleted_count']);
        $this->assertSame(0, $result['freed_bytes']);
    }

    public function test_summary_for_empty_disk_returns_zeros(): void
    {
        $summary = app(GraveyardService::class)->summary();

        $this->assertSame(0, $summary['fullsize']['file_count']);
        $this->assertSame(0, $summary['fullsize']['total_bytes']);
        $this->assertSame(0, $summary['fullsize']['aged_file_count']);
        $this->assertNull($summary['fullsize']['oldest_buried_at']);
        $this->assertSame(0, $summary['archive']['file_count']);
    }
}
