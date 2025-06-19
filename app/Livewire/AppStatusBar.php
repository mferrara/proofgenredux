<?php

namespace App\Livewire;

use App\Services\HorizonService;
use Flux\Flux;
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
                    text: 'Failed to start Horizon. Check logs for details.',
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
                    text: 'Horizon has been stopped successfully.',
                    heading: 'Horizon Stopped',
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

    public function render()
    {
        // Check if Horizon is running using the service
        $horizonService = app(HorizonService::class);
        $isHorizonRunning = $horizonService->isRunning();

        return view('livewire.app-status-bar', [
            'isHorizonRunning' => $isHorizonRunning,
            'autoRestartEnabled' => config('proofgen.auto_restart_horizon', false),
        ]);
    }
}
