<?php

namespace App\Services;

use App\Jobs\RestartHorizon;
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

        } catch (\Throwable $e) {
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
            return Artisan::call('horizon:terminate') === 0;
        } catch (\Throwable $e) {
            Log::error('Error terminating Horizon: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Start Horizon in a fully detached process.
     *
     * Idempotent: if Horizon is already running the existing master is kept and
     * no second process is spawned. The launcher returns as soon as the shell
     * has backgrounded Horizon, then this method waits a bounded amount of time
     * for Horizon to actually report running before claiming success.
     */
    public function start(): bool
    {
        try {
            // Idempotent: never stack a second master supervisor on top of a
            // running one.
            if ($this->isRunning()) {
                return true;
            }

            $this->ensureHorizonLogDirectoryExists();

            if (! $this->launchDetached($this->buildStartCommand())) {
                Log::error('Failed to start Horizon process: detached launcher did not run');

                return false;
            }

            if ($this->waitUntilRunning()) {
                return true;
            }

            // The launcher succeeded but Horizon has not reported running yet.
            // Report honestly rather than claiming a start we could not verify.
            Log::warning('Horizon did not report running within the startup window; it may still be starting');

            return false;
        } catch (\Throwable $e) {
            Log::error('Failed to start Horizon: '.$e->getMessage());

            return false;
        }
    }

    /**
     * How long start() waits for Horizon to report running, in seconds.
     */
    protected function readinessTimeoutSeconds(): float
    {
        return 8.0;
    }

    /**
     * Delay between readiness probes, in microseconds.
     */
    protected function readinessPollMicroseconds(): int
    {
        return 250000;
    }

    /**
     * PHP binary used to launch Horizon.
     */
    protected function phpBinary(): string
    {
        return Configuration::getPhpBinary();
    }

    /**
     * Directory the detached Horizon process is launched from.
     */
    protected function basePath(): string
    {
        return base_path();
    }

    /**
     * File Horizon's stdout/stderr are appended to.
     */
    protected function horizonLogPath(): string
    {
        return storage_path('logs/horizon.log');
    }

    /**
     * Ensure the directory holding the Horizon log exists.
     */
    protected function ensureHorizonLogDirectoryExists(): void
    {
        $directory = dirname($this->horizonLogPath());

        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }
    }

    /**
     * Build the shell command that launches Horizon.
     *
     * Every path is passed through escapeshellarg() so paths containing spaces
     * (for example a Herd/PHP binary under a home directory with a space) arrive
     * at the shell as a single argument. stdin is re-pointed at /dev/null and the
     * whole pipeline is backgrounded so the launcher can exit immediately.
     */
    protected function buildStartCommand(): string
    {
        return sprintf(
            'cd %s && nohup %s artisan horizon >> %s 2>&1 < /dev/null &',
            escapeshellarg($this->basePath()),
            escapeshellarg($this->phpBinary()),
            escapeshellarg($this->horizonLogPath())
        );
    }

    /**
     * Launch a command with every standard stream detached.
     *
     * proc_open() is given file descriptors (never pipes) so PHP never waits on
     * a pipe held open by the long-lived Horizon process. Combined with the
     * command's own redirections and the trailing "&", this returns as soon as
     * the launcher shell exits and leaves Horizon reparented and independent of
     * the web request.
     */
    protected function launchDetached(string $command): bool
    {
        $logPath = $this->horizonLogPath();

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $logPath, 'a'],
            2 => ['file', $logPath, 'a'],
        ];

        $process = @proc_open($command, $descriptors, $pipes, $this->basePath());

        if (! is_resource($process)) {
            return false;
        }

        // The launcher shell backgrounds Horizon and exits immediately, so this
        // does not wait on the daemon itself.
        $exitCode = proc_close($process);

        return $exitCode === 0;
    }

    /**
     * Poll isRunning() until Horizon comes up or the bounded window expires.
     */
    protected function waitUntilRunning(): bool
    {
        $deadline = microtime(true) + $this->readinessTimeoutSeconds();

        while (true) {
            if ($this->isRunning()) {
                return true;
            }

            if (microtime(true) >= $deadline) {
                return false;
            }

            usleep($this->readinessPollMicroseconds());
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
        Queue::push(new RestartHorizon);

        // Log::info('Scheduled Horizon restart job');
    }

    /**
     * Restart Horizon directly without using queue
     * This is useful when Horizon is stuck and can't process queued jobs
     */
    public function restartDirect(): bool
    {
        try {
            // Log::info('Directly restarting Horizon (without queue)');

            // First terminate if running
            if ($this->isRunning()) {
                // Log::debug('Terminating existing Horizon process');
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
            // Log::debug('Starting Horizon');
            $result = $this->start();

            if ($result) {
                // Log::info('Horizon restarted successfully');
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
                // Log::info('No Horizon processes found to kill');

                return true;
            }

            $pidArray = array_filter(explode("\n", trim($pids)));

            foreach ($pidArray as $pid) {
                if (! empty($pid) && is_numeric($pid)) {
                    // Log::debug("Killing Horizon process: $pid");
                    exec("kill -9 $pid");
                }
            }

            // Give processes time to die
            sleep(1);

            // Verify they're gone
            $remainingPids = shell_exec("ps aux | grep '[p]hp.*artisan horizon' | awk '{print $2}'");

            if (empty(trim($remainingPids))) {
                // Log::info('All Horizon processes killed successfully');

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
