<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Intervention\Image\Image as InterventionImage;
use Intervention\Image\ImageManager;

class CoreImageDaemonService extends ImageEnhancementService
{
    protected ImageManager $manager;

    protected bool $coreImageAvailable;

    protected string $daemonHost = '127.0.0.1';

    protected int $daemonPort = 9876;

    protected float $timeout = 30.0;

    public function __construct()
    {
        parent::__construct();
        $this->manager = ImageManager::gd();
        $this->coreImageAvailable = false;

        // Check if we're on macOS
        if (PHP_OS_FAMILY === 'Darwin') {
            $this->coreImageAvailable = $this->checkCoreImageAvailability();
        }
    }

    /**
     * Check if Core Image daemon is available
     */
    protected function checkCoreImageAvailability(): bool
    {
        try {
            // Try to connect to the daemon
            $socket = @fsockopen($this->daemonHost, $this->daemonPort, $errno, $errstr, 1);

            if (! $socket) {
                // Use file locking to ensure only one process starts the daemon
                $lockFile = storage_path('core-image-daemon.lock');
                $lockHandle = fopen($lockFile, 'c');

                if (! $lockHandle) {
                    Log::warning('CoreImageDaemonService: Could not create lock file');

                    return false;
                }

                // Try to get exclusive lock
                if (flock($lockHandle, LOCK_EX | LOCK_NB)) {
                    try {
                        // Double-check daemon isn't running now
                        $socket = @fsockopen($this->daemonHost, $this->daemonPort, $errno, $errstr, 1);
                        if ($socket) {
                            fclose($socket);
                            flock($lockHandle, LOCK_UN);
                            fclose($lockHandle);
                            Log::info('CoreImageDaemonService: Daemon is available and working');

                            return true;
                        }

                        Log::info('CoreImageDaemonService: Daemon not running, attempting to start it');

                        // Try to start the daemon
                        if ($this->startDaemonProcess()) {
                            // Wait a moment for it to start
                            sleep(2);

                            // Try connecting again
                            $socket = @fsockopen($this->daemonHost, $this->daemonPort, $errno, $errstr, 1);
                            if (! $socket) {
                                Log::warning('CoreImageDaemonService: Failed to connect to daemon after starting');
                                flock($lockHandle, LOCK_UN);
                                fclose($lockHandle);

                                return false;
                            }
                            fclose($socket);
                        } else {
                            flock($lockHandle, LOCK_UN);
                            fclose($lockHandle);

                            return false;
                        }
                    } finally {
                        flock($lockHandle, LOCK_UN);
                        fclose($lockHandle);
                    }
                } else {
                    // Another process is starting the daemon, wait and retry
                    fclose($lockHandle);
                    Log::info('CoreImageDaemonService: Another process is starting the daemon, waiting...');
                    sleep(3);

                    // Try connecting again
                    $socket = @fsockopen($this->daemonHost, $this->daemonPort, $errno, $errstr, 1);
                    if (! $socket) {
                        Log::warning('CoreImageDaemonService: Daemon still not available after waiting');

                        return false;
                    }
                    fclose($socket);
                }
            } else {
                fclose($socket);
            }

            Log::info('CoreImageDaemonService: Daemon is available and working');

            return true;

        } catch (\Exception $e) {
            Log::warning('CoreImageDaemonService: Failed to check availability - '.$e->getMessage());

            return false;
        }
    }

    /**
     * Start the Core Image daemon (public wrapper)
     */
    public function startDaemon(): bool
    {
        return $this->startDaemonProcess();
    }

    /**
     * Start the Core Image daemon process
     */
    protected function startDaemonProcess(): bool
    {
        try {
            // Check if a daemon is already running via PID file
            $pidFile = storage_path('core-image-daemon.pid');
            if (file_exists($pidFile)) {
                $pid = trim(file_get_contents($pidFile));
                if (is_numeric($pid)) {
                    // Check if process is still running
                    $result = shell_exec("ps -p $pid");
                    if (strpos($result, $pid) !== false) {
                        Log::info("CoreImageDaemonService: Daemon already running with PID $pid");

                        return true;
                    }
                }
                // PID file exists but process is not running, remove stale PID file
                unlink($pidFile);
            }

            $daemonPath = app_path('Services/CoreImage/ProofgenImageEnhancerDaemon.swift');

            if (! file_exists($daemonPath)) {
                Log::error('CoreImageDaemonService: Daemon script not found at '.$daemonPath);

                return false;
            }

            // Start the daemon in the background
            $command = sprintf(
                'nohup swift %s --daemon > %s 2>&1 & echo $!',
                escapeshellarg($daemonPath),
                escapeshellarg(storage_path('logs/core-image-daemon.log'))
            );

            $pid = trim(shell_exec($command));

            if (is_numeric($pid)) {
                Log::info("CoreImageDaemonService: Started daemon with PID $pid");

                // Save PID for later management
                file_put_contents(storage_path('core-image-daemon.pid'), $pid);

                return true;
            }

            return false;

        } catch (\Exception $e) {
            Log::error('CoreImageDaemonService: Failed to start daemon - '.$e->getMessage());

            return false;
        }
    }

    /**
     * Check if Core Image is available
     */
    public function isCoreImageAvailable(): bool
    {
        return $this->coreImageAvailable;
    }

    /**
     * Apply enhancement to an image using Core Image
     */
    public function enhance(string $imagePath, string $method, array $parameters = []): InterventionImage
    {
        if (! $this->coreImageAvailable) {
            // Fall back to parent implementation
            return parent::enhance($imagePath, $method, $parameters);
        }

        try {
            $startTime = microtime(true);

            // Create temporary output file
            $tempPath = tempnam(sys_get_temp_dir(), 'coreimage_enhance_').'.jpg';

            // Prepare request data
            $request = [
                'method' => $method,
                'inputPath' => $imagePath,
                'outputPath' => $tempPath,
                'parameters' => $this->prepareParameters($method, $parameters),
            ];

            // Execute enhancement via daemon
            $response = $this->sendRequestToDaemon($request);

            if (! $response['success']) {
                throw new \Exception($response['error'] ?? 'Unknown error');
            }

            $processingTime = microtime(true) - $startTime;
            Log::debug("Core Image {$method} processing time: {$processingTime}s");

            // Load the enhanced image
            $result = $this->manager->read($tempPath);

            // Clean up
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }

            return $result;

        } catch (\Exception $e) {
            Log::error('Core Image enhancement failed, falling back to standard service: '.$e->getMessage());

            return parent::enhance($imagePath, $method, $parameters);
        }
    }

    /**
     * Send request to Core Image daemon
     */
    protected function sendRequestToDaemon(array $request): array
    {
        $socket = @fsockopen($this->daemonHost, $this->daemonPort, $errno, $errstr, $this->timeout);

        if (! $socket) {
            throw new \Exception("Failed to connect to Core Image daemon: $errstr ($errno)");
        }

        try {
            // Set socket timeout
            stream_set_timeout($socket, (int) $this->timeout);

            // Send request
            $json = json_encode($request);
            $written = fwrite($socket, $json);

            if ($written === false || $written < strlen($json)) {
                throw new \Exception('Failed to send complete request to daemon');
            }

            // Read response
            $response = '';
            $attempts = 0;
            $maxAttempts = 100; // 10 seconds max

            while ($attempts < $maxAttempts) {
                $chunk = fread($socket, 65536);
                if ($chunk !== false && strlen($chunk) > 0) {
                    $response .= $chunk;

                    // Check if we have a complete JSON response
                    $decoded = json_decode($response, true);
                    if ($decoded !== null && json_last_error() === JSON_ERROR_NONE) {
                        return $decoded;
                    }
                }

                // Check if socket is closed
                if (feof($socket)) {
                    break;
                }

                // Small delay to avoid busy waiting
                usleep(100000); // 0.1 second
                $attempts++;
            }

            throw new \Exception('Invalid or incomplete response from daemon');
        } finally {
            fclose($socket);
        }
    }

    /**
     * Prepare parameters for Core Image
     */
    protected function prepareParameters(string $method, array $parameters): array
    {
        $prepared = [];

        // Convert parameter names and ensure they're floats
        foreach ($parameters as $key => $value) {
            $prepared[$key] = (float) $value;
        }

        // Add default values based on method
        switch ($method) {
            case 'percentile_clipping':
            case 'advanced_tone_mapping':
                $prepared['tone_mapping_percentile_low'] = $prepared['tone_mapping_percentile_low'] ?? 0.1;
                $prepared['tone_mapping_percentile_high'] = $prepared['tone_mapping_percentile_high'] ?? 99.9;
                $prepared['tone_mapping_shadow_amount'] = $prepared['tone_mapping_shadow_amount'] ?? 0.0;
                $prepared['tone_mapping_highlight_amount'] = $prepared['tone_mapping_highlight_amount'] ?? 0.0;
                $prepared['tone_mapping_shadow_radius'] = $prepared['tone_mapping_shadow_radius'] ?? 30.0;
                $prepared['tone_mapping_midtone_gamma'] = $prepared['tone_mapping_midtone_gamma'] ?? 1.0;
                break;

            case 'basic_auto_levels':
            case 'adjustable_auto_levels':
                $prepared['auto_levels_target_brightness'] = $prepared['auto_levels_target_brightness'] ?? 128.0;
                $prepared['auto_levels_contrast_threshold'] = $prepared['auto_levels_contrast_threshold'] ?? 200.0;
                $prepared['auto_levels_contrast_boost'] = $prepared['auto_levels_contrast_boost'] ?? 1.2;
                $prepared['auto_levels_black_point'] = $prepared['auto_levels_black_point'] ?? 0.0;
                $prepared['auto_levels_white_point'] = $prepared['auto_levels_white_point'] ?? 100.0;
                break;
        }

        return $prepared;
    }

    /**
     * Stop the daemon if it's running
     */
    public function stopDaemon(): bool
    {
        $pidFile = storage_path('core-image-daemon.pid');

        if (file_exists($pidFile)) {
            $pid = trim(file_get_contents($pidFile));

            if (is_numeric($pid)) {
                // Check if process is running
                $result = shell_exec("ps -p $pid");
                if (strpos($result, $pid) !== false) {
                    // Kill the process
                    shell_exec("kill $pid");
                    Log::info("CoreImageDaemonService: Stopped daemon with PID $pid");
                }

                unlink($pidFile);

                return true;
            }
        }

        return false;
    }
}
