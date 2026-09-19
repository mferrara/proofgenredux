<?php

namespace App\Livewire;

use App\Models\PhotoIssue;
use App\Services\GraveyardService;
use App\Services\HorizonService;
use App\Services\WorkerActivityService;
use App\Services\WorkingDiskSpace;
use App\Services\WorkingFolderHealth;
use Flux\Flux;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;

class AppStatusBar extends Component
{
    // Reload when the configuration changes
    protected $listeners = [
        'config-updated' => 'reload',
    ];

    #[On('config-updated')]
    public function reload()
    {
        Log::debug('AppStatusBar reload called');
    }

    /**
     * Start Horizon
     */
    public function startHorizon()
    {
        Log::info('Starting Horizon from AppStatusBar');

        try {
            // Get the HorizonService
            $horizonService = app(HorizonService::class);

            // Start Horizon directly
            if ($horizonService->start()) {
                Flux::toast(
                    text: 'Horizon has been started successfully.',
                    heading: 'Horizon Started',
                    variant: 'success',
                    position: 'top right'
                );
            } else {
                Flux::toast(
                    text: 'Horizon has not reported running yet. Status will refresh automatically; check the Horizon log if it stays stopped.',
                    heading: 'Start Failed',
                    variant: 'danger',
                    position: 'top right'
                );
            }
        } catch (\Exception $e) {
            Log::error('Error starting Horizon: '.$e->getMessage());

            Flux::toast(
                text: 'Error starting Horizon: '.$e->getMessage(),
                heading: 'Start Failed',
                variant: 'danger',
                position: 'top right'
            );
        }
    }

    /**
     * Stop Horizon
     */
    public function stopHorizon()
    {
        Log::info('Stopping Horizon from AppStatusBar');

        try {
            // Get the HorizonService
            $horizonService = app(HorizonService::class);

            // Stop Horizon
            if ($horizonService->stop()) {
                Flux::toast(
                    text: 'Workers will stop after finishing their current jobs.',
                    heading: 'Stop Requested',
                    variant: 'success',
                    position: 'top right'
                );
            } else {
                Flux::toast(
                    text: 'Failed to stop Horizon. Check logs for details.',
                    heading: 'Stop Failed',
                    variant: 'danger',
                    position: 'top right'
                );
            }
        } catch (\Exception $e) {
            Log::error('Error stopping Horizon: '.$e->getMessage());

            Flux::toast(
                text: 'Error stopping Horizon: '.$e->getMessage(),
                heading: 'Stop Failed',
                variant: 'danger',
                position: 'top right'
            );
        }
    }

    /**
     * Restart Horizon directly
     */
    public function restartHorizon()
    {
        Log::info('Restarting Horizon from AppStatusBar');

        try {
            // Get the HorizonService
            $horizonService = app(HorizonService::class);

            // Use direct restart
            if ($horizonService->restartDirect()) {
                Flux::toast(
                    text: 'Horizon has been restarted successfully.',
                    heading: 'Horizon Restarted',
                    variant: 'success',
                    position: 'top right'
                );
            } else {
                Flux::toast(
                    text: 'Failed to restart Horizon. Check logs for details.',
                    heading: 'Restart Failed',
                    variant: 'danger',
                    position: 'top right'
                );
            }
        } catch (\Exception $e) {
            Log::error('Error restarting Horizon: '.$e->getMessage());

            Flux::toast(
                text: 'Error restarting Horizon: '.$e->getMessage(),
                heading: 'Restart Failed',
                variant: 'danger',
                position: 'top right'
            );
        }
    }

    public function snoozeGraveyardAlert(): void
    {
        // 25h TTL so the cache entry outlives the 24h "snoozed_until" timestamp itself.
        Cache::put('graveyard_alert_snoozed_until', now()->addHours(24), now()->addHours(25));
    }

    /**
     * Returns either ['agedTotal' => int, 'disks' => string[]] when an alert
     * banner should be shown, or null when there's nothing to alert about
     * (no aged files, or the user has snoozed within the last 24h).
     */
    public function gravyardAlert(): ?array
    {
        $snoozedUntil = Cache::get('graveyard_alert_snoozed_until');
        if ($snoozedUntil instanceof \DateTimeInterface && now()->lt($snoozedUntil)) {
            return null;
        }

        $summary = Cache::remember('app-status-graveyard-summary', 60, fn () => app(GraveyardService::class)->summary());
        $agedDisks = [];
        $agedTotal = 0;

        foreach ($summary as $disk => $stats) {
            if (($stats['aged_file_count'] ?? 0) > 0) {
                $agedDisks[] = $disk;
                $agedTotal += $stats['aged_file_count'];
            }
        }

        if ($agedTotal === 0) {
            return null;
        }

        return [
            'agedTotal' => $agedTotal,
            'disks' => $agedDisks,
        ];
    }

    /**
     * Already-imported camera files that can be removed safely, or null when
     * there is nothing worth mentioning: below the configured size the strip
     * stays away unless the disk is running low.
     *
     * @return array{count:int, bytes:int}|null
     */
    public function redundantCopies(): ?array
    {
        $redundant = app(GraveyardService::class)->redundantImportCopies();
        if ($redundant['count'] === 0) {
            return null;
        }

        $worthMentioning = $redundant['bytes'] >= config('proofgen.graveyard.redundant_alert_gb', 1) * 1024 ** 3
            || app(WorkingDiskSpace::class)->level() !== null;

        return $worthMentioning ? $redundant : null;
    }

    public function removeRedundantCopies(): void
    {
        // Every original is read back and hashed before its copy goes; a whole show takes a while.
        set_time_limit(0);
        $result = app(GraveyardService::class)->removeRedundantImportCopies();

        Flux::toast(
            text: $result['deleted_count'].' copies removed, '.WorkingDiskSpace::readable($result['freed_bytes']).' freed.'
                .($result['kept_count'] ? ' '.$result['kept_count'].' were kept because their imported original did not match; see the Graveyard page.' : ''),
            heading: 'Space freed',
            variant: $result['kept_count'] ? 'warning' : 'success',
            position: 'top right'
        );
    }

    public function openIssuesCount(): int
    {
        return PhotoIssue::open()->count();
    }

    public function render()
    {
        // Check if Horizon is running using the service
        $horizonService = app(HorizonService::class);
        $isHorizonRunning = $horizonService->isRunning();

        return view('livewire.app-status-bar', [
            'isHorizonRunning' => $isHorizonRunning,
            'activity' => app(WorkerActivityService::class)->snapshot(),
            'autoRestartEnabled' => config('proofgen.auto_restart_horizon', false),
            'graveyardAlert' => $this->gravyardAlert(),
            'redundantCopies' => $this->redundantCopies(),
            'diskLevel' => app(WorkingDiskSpace::class)->level(),
            'diskFree' => app(WorkingDiskSpace::class)->freeBytes(),
            'workingFolderProblem' => app(WorkingFolderHealth::class)->problem(),
            'openIssuesCount' => $this->openIssuesCount(),
        ]);
    }
}
