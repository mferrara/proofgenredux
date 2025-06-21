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
     */
    public function isRunning(): bool
    {
        try {
            // Use Artisan::call directly to run the horizon:status command without shell execution
            // Log::debug('Checking Horizon status using Artisan::call');

            $exitCode = Artisan::call('horizon:status');
            $output = Artisan::output();

            // Log::debug('Horizon status check output: ' . $output . ' (exit code: ' . $exitCode . ')');

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

            return ! empty($ps_output);

        } catch (\Exception $e) {
            Log::error('Error checking Horizon status: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Get detailed Horizon process information
     */
    public function getProcessInfo(): array
    {
        try {
            $phpBinary = Configuration::getPhpBinary();

            // Get main Horizon process
            $mainProcess = shell_exec("ps aux | grep '[p]hp.*artisan horizon$' | grep -v 'horizon:work' | grep -v 'horizon:supervisor'");

            if (empty($mainProcess)) {
                return [
                    'running' => false,
                    'processes' => [],
                ];
            }

            $processes = [];
            $lines = explode("\n", trim($mainProcess));

            foreach ($lines as $line) {
                if (empty($line)) {
                    continue;
                }

                // Parse ps output
                $parts = preg_split('/\s+/', $line, 11);
                if (count($parts) >= 11) {
                    $processes[] = [
                        'user' => $parts[0],
                        'pid' => $parts[1],
                        'cpu' => $parts[2],
                        'memory' => $parts[3],
                        'start_time' => $parts[8],
                        'command' => $parts[10],
                    ];
                }
            }

            // Count supervisor and worker processes
            $supervisorCount = (int) shell_exec("ps aux | grep '[h]orizon:supervisor' | wc -l");
            $workerCount = (int) shell_exec("ps aux | grep '[h]orizon:work' | wc -l");

            return [
                'running' => true,
                'main_process' => $processes[0] ?? null,
                'supervisor_count' => $supervisorCount,
                'worker_count' => $workerCount,
                'total_processes' => 1 + $supervisorCount + $workerCount,
            ];

        } catch (\Exception $e) {
            Log::error('Error getting Horizon process info: '.$e->getMessage());

            return [
                'running' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Terminate the running Horizon process
     */
    public function terminate(): bool
    {
        try {
            // First try to terminate using Artisan::call
            Log::debug('Terminating Horizon using Artisan::call');

            $exitCode = Artisan::call('horizon:terminate');
            $output = Artisan::output();
            Log::debug('Horizon terminate output: '.$output);

            return true;
        } catch (\Exception $e) {
            Log::error('Error using Artisan::call to terminate Horizon: '.$e->getMessage());

            // Fall back to shell exec
            // Get the PHP binary path for fallback
            $phpBinary = Configuration::getPhpBinary();
            Log::debug('Falling back to shell exec with PHP binary: '.$phpBinary);

            // Change directory to the application root to ensure the command works
            $terminateCommand = 'cd '.base_path().' && '.escapeshellarg($phpBinary).' artisan horizon:terminate > /dev/null 2>&1';
            Log::debug('Running command: '.$terminateCommand);
            exec($terminateCommand);

            return true;
        }
    }

    /**
     * Start Horizon in a detached process
     */
    public function start(): bool
    {
        try {
            // Get the PHP binary path
            $phpBinary = Configuration::getPhpBinary();

            // Command to execute
            $command = 'cd '.base_path().' && '.escapeshellarg($phpBinary).' artisan horizon';

            // Descriptors for proc_open
            $descriptorspec = [
                0 => ['file', '/dev/null', 'r'],  // stdin
                1 => ['file', storage_path('logs/horizon.log'), 'a'], // stdout
                2 => ['file', storage_path('logs/horizon.log'), 'a'],  // stderr
            ];

            // Current working directory and environment variables
            $cwd = base_path();
            $env = null; // Use current environment

            // Use shell_exec to start Horizon in the background
            $fullCommand = sprintf(
                'cd %s && nohup %s artisan horizon > %s 2>&1 &',
                escapeshellarg($cwd),
                escapeshellarg($phpBinary),
                escapeshellarg(storage_path('logs/horizon.log'))
            );
            
            shell_exec($fullCommand);
            
            // Give it a moment to start
            usleep(500000); // 0.5 seconds
            
            // Check if it started successfully
            if ($this->isRunning()) {
                Log::debug('Successfully started Horizon process');
                return true;
            } else {
                Log::error('Failed to start Horizon process');
                return false;
            }
        } catch (\Exception $e) {
            Log::error('Failed to start Horizon: '.$e->getMessage());

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
        Queue::push(new \App\Jobs\RestartHorizon);

        Log::info('Scheduled Horizon restart job');
    }

    /**
     * Restart Horizon directly without using queue
     * This is useful when Horizon is stuck and can't process queued jobs
     */
    public function restartDirect(): bool
    {
        try {
            Log::info('Directly restarting Horizon (without queue)');

            // First terminate if running
            if ($this->isRunning()) {
                Log::debug('Terminating existing Horizon process');
                $this->terminate();

                // Wait for process to stop (max 5 seconds)
                $startTime = time();
                $maxWaitTime = 5;

                while ($this->isRunning() && (time() - $startTime < $maxWaitTime)) {
                    usleep(500000); // 500ms
                }

                if ($this->isRunning()) {
                    Log::warning('Horizon did not stop gracefully, attempting force kill');
                    $this->forceKill();
                    sleep(1); // Give it a moment after force kill
                }
            }

            // Start Horizon
            Log::debug('Starting Horizon');
            $result = $this->start();

            if ($result) {
                Log::info('Horizon restarted successfully');
            } else {
                Log::error('Failed to restart Horizon');
            }

            return $result;

        } catch (\Exception $e) {
            Log::error('Error during direct Horizon restart: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Force kill all Horizon processes
     * This should only be used when terminate() doesn't work
     */
    public function forceKill(): bool
    {
        try {
            Log::warning('Force killing Horizon processes');

            // Get all Horizon process PIDs
            $pids = shell_exec("ps aux | grep '[p]hp.*artisan horizon' | awk '{print $2}'");

            if (empty($pids)) {
                Log::info('No Horizon processes found to kill');

                return true;
            }

            $pidArray = array_filter(explode("\n", trim($pids)));

            foreach ($pidArray as $pid) {
                if (! empty($pid) && is_numeric($pid)) {
                    Log::debug("Killing Horizon process: $pid");
                    exec("kill -9 $pid");
                }
            }

            // Give processes time to die
            sleep(1);

            // Verify they're gone
            $remainingPids = shell_exec("ps aux | grep '[p]hp.*artisan horizon' | awk '{print $2}'");

            if (empty(trim($remainingPids))) {
                Log::info('All Horizon processes killed successfully');

                return true;
            } else {
                Log::error('Some Horizon processes may still be running');

                return false;
            }

        } catch (\Exception $e) {
            Log::error('Error force killing Horizon: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Stop Horizon gracefully
     * Alias for terminate() for UI consistency
     */
    public function stop(): bool
    {
        return $this->terminate();
    }
}
