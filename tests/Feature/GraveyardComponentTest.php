<?php

namespace Tests\Feature;

use App\Livewire\AppStatusBar;
use App\Livewire\GraveyardComponent;
use App\Services\SafeFileMover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class GraveyardComponentTest extends TestCase
{
    use RefreshDatabase;

    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempPath = storage_path('app/graveyard_component_test_'.uniqid());
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

        Cache::forget('graveyard_alert_snoozed_until');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if (isset($this->tempPath) && File::exists($this->tempPath)) {
            File::deleteDirectory($this->tempPath);
        }

        parent::tearDown();
    }

    private function buryAt(string $disk, string $path, string $contents, string $when): array
    {
        Carbon::setTestNow($when);
        Storage::disk($disk)->put($path, $contents);

        return app(SafeFileMover::class)->bury(
            disk: $disk,
            path: $path,
            reason: SafeFileMover::REASON_POST_IMPORT_SOURCE,
        );
    }

    public function test_component_renders_summary_for_each_disk(): void
    {
        $this->buryAt('fullsize', 'SHOW1/101/old.jpg', 'old-bytes', '2026-01-01 10:00:00');
        Carbon::setTestNow('2026-05-09 12:00:00');

        Livewire::test(GraveyardComponent::class)
            ->assertSuccessful()
            ->assertSee('Graveyard')
            ->assertSee('fullsize')
            ->assertSee('archive');
    }

    public function test_purge_button_calls_service_and_removes_aged_files(): void
    {
        $old = $this->buryAt('fullsize', 'SHOW1/101/old.jpg', 'old-bytes', '2026-01-01 10:00:00');
        $recent = $this->buryAt('fullsize', 'SHOW1/101/recent.jpg', 'recent-bytes', '2026-05-01 10:00:00');

        Carbon::setTestNow('2026-05-09 12:00:00');

        Livewire::test(GraveyardComponent::class)
            ->call('confirmPurge', 'fullsize')
            ->assertSet('pendingPurgeDisk', 'fullsize')
            ->call('purge')
            ->assertSet('pendingPurgeDisk', null);

        $this->assertFalse(Storage::disk('fullsize')->exists($old['graveyard_path']));
        $this->assertTrue(Storage::disk('fullsize')->exists($recent['graveyard_path']));
    }

    public function test_select_disk_switches_listed_entries(): void
    {
        $this->buryAt('archive', 'SHOW1/101/archived.jpg', 'a', '2026-04-01 10:00:00');

        Livewire::test(GraveyardComponent::class)
            ->call('selectDisk', 'archive')
            ->assertSet('disk', 'archive');
    }

    public function test_status_bar_shows_aged_alert_when_files_aged(): void
    {
        $this->buryAt('fullsize', 'SHOW1/101/old.jpg', 'old-bytes', '2026-01-01 10:00:00');
        Carbon::setTestNow('2026-05-09 12:00:00');

        Livewire::test(AppStatusBar::class)
            ->assertSee('aged file');
    }

    public function test_snooze_hides_alert_for_24_hours(): void
    {
        $this->buryAt('fullsize', 'SHOW1/101/old.jpg', 'old-bytes', '2026-01-01 10:00:00');
        Carbon::setTestNow('2026-05-09 12:00:00');

        Livewire::test(AppStatusBar::class)
            ->call('snoozeGraveyardAlert')
            ->assertDontSee('aged file');

        $until = Cache::get('graveyard_alert_snoozed_until');
        $this->assertNotNull($until);
        $this->assertEqualsWithDelta(
            now()->addHours(24)->getTimestamp(),
            Carbon::instance($until)->getTimestamp(),
            5,
        );
    }

    public function test_alert_returns_after_snooze_expires(): void
    {
        $this->buryAt('fullsize', 'SHOW1/101/old.jpg', 'old-bytes', '2026-01-01 10:00:00');

        Carbon::setTestNow('2026-05-09 12:00:00');
        Livewire::test(AppStatusBar::class)->call('snoozeGraveyardAlert');

        Carbon::setTestNow('2026-05-10 13:00:00');
        Livewire::test(AppStatusBar::class)
            ->assertSee('aged file');
    }
}
