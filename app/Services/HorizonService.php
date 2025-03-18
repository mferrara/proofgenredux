<?php

namespace App\Services;

use App\Models\Configuration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class HorizonService
{
    /**
     * Check if Horizon is running
     *
     * @return bool
     */
    public function isRunning(): bool
    {
        try {
            // Use Artisan::call directly to run the horizon:status command without shell execution
            Log::debug('Checking Horizon status using Artisan::call');

            $exitCode = Artisan::call('horizon:status');
            $output = Artisan::output();

            Log::debug('Horizon status check output: ' . $output . ' (exit code: ' . $exitCode . ')');

            // If we see "Horizon is running", then it's active
            if (strpos($output, 'Horizon is running') !== false) {
                return true;
            }

            // Explicitly check for inactive status
            if (strpos($output, 'Horizon is inactive') !== false || $exitCode === 2) {
                return false;
            }

            // More specific process check if we get here
            // This uses pgrep which is more precise than grep for process matching
            $ps_output = shell_exec("ps -ef | grep '[p]hp.*[a]rtisan horizon$' | grep -v 'horizon:status'");
            return !empty($ps_output);

        } catch (\Exception $e) {
            Log::error('Error checking Horizon status: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Terminate the running Horizon process
     *
     * @return bool
     */
    public function terminate(): bool
    {
        try {
            // First try to terminate using Artisan::call
            Log::debug('Terminating Horizon using Artisan::call');

            $exitCode = Artisan::call('horizon:terminate');
            $output = Artisan::output();
            Log::debug('Horizon terminate output: ' . $output);

            return true;
        } catch (\Exception $e) {
            Log::error('Error using Artisan::call to terminate Horizon: ' . $e->getMessage());

            // Fall back to shell exec
            // Get the PHP binary path for fallback
            $phpBinary = Configuration::getPhpBinary();
            Log::debug('Falling back to shell exec with PHP binary: ' . $phpBinary);

            // Change directory to the application root to ensure the command works
            $terminateCommand = 'cd ' . base_path() . ' && ' . escapeshellarg($phpBinary) . ' artisan horizon:terminate > /dev/null 2>&1';
            Log::debug('Running command: ' . $terminateCommand);
            exec($terminateCommand);

            return true;
        }
    }

    /**
     * Start Horizon in a detached process
     *
     * @return bool
     */
    public function start(): bool
    {
        try {
            // Get the PHP binary path
            $phpBinary = Configuration::getPhpBinary();

            // Command to execute
            $command = 'cd ' . base_path() . ' && ' . escapeshellarg($phpBinary) . ' artisan horizon';

            // Descriptors for proc_open
            $descriptorspec = [
                0 => ['file', '/dev/null', 'r'],  // stdin
                1 => ['file', storage_path('logs/horizon.log'), 'a'], // stdout
                2 => ['file', storage_path('logs/horizon.log'), 'a']  // stderr
            ];

            // Current working directory and environment variables
            $cwd = base_path();
            $env = null; // Use current environment

            // Open the process
            $process = proc_open($command, $descriptorspec, $pipes, $cwd, $env, ['bypass_shell' => true]);

            // Check if process started successfully
            if (is_resource($process)) {
                // This is critical: make the process run independently
                proc_close($process);
                Log::debug('Successfully started Horizon process');
                return true;
            } else {
                Log::error('Failed to start Horizon process');
                return false;
            }
        } catch (\Exception $e) {
            Log::error('Failed to start Horizon: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Restart Horizon by scheduling a job
     * This avoids timeouts in the HTTP request
     */
    public function scheduleRestart(): void
    {
        // Push a job to the queue to restart Horizon
        // This is intentionally sent to the 'default' queue,
        // not 'horizon' which would be processed by the Horizon worker we're restarting
        Queue::push(new \App\Jobs\RestartHorizon());

        Log::info('Scheduled Horizon restart job');
    }
}
