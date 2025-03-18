<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

class RestartHorizon implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $backoff = 5;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 30; // Increased timeout

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        // Make sure this job is processed on the default queue, not horizon
        $this->onQueue('default');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('RestartHorizon job: Restarting Horizon process');

        // Inject the HorizonService
        $horizonService = App::make('App\Services\HorizonService');

        // Check if Horizon is running
        $isRunning = $horizonService->isRunning();

        if ($isRunning) {
            // Terminate if running
            Log::debug('RestartHorizon job: Terminating Horizon');
            $horizonService->terminate();

            $startTime = time();
            $maxWaitTime = 3; // Maximum seconds to wait

            while ($horizonService->isRunning() && (time() - $startTime < $maxWaitTime)) {
                // Short sleep to avoid CPU spinning
                usleep(100000); // 100ms
            }

            // If it's still running after max wait time, log a warning
            if ($horizonService->isRunning()) {
                Log::warning('RestartHorizon job: Horizon did not terminate within expected time');
            }
        }

        // Start Horizon
        Log::debug('RestartHorizon job: Starting Horizon');
        $result = $horizonService->start();

        if ($result) {
            Log::info('RestartHorizon job: Horizon start command executed successfully');
        } else {
            Log::error('RestartHorizon job: Failed to execute Horizon start command');
            $this->fail(new \Exception('Failed to start Horizon'));
        }
    }
}
