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
