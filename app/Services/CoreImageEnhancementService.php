<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Intervention\Image\Image as InterventionImage;
use Intervention\Image\ImageManager;
use Symfony\Component\Process\Process;

class CoreImageEnhancementService extends ImageEnhancementService
{
    protected ImageManager $manager;

    protected bool $coreImageAvailable;

    protected ?Process $swiftProcess = null;

    protected string $swiftToolPath;

    public function __construct()
    {
        parent::__construct();
        $this->manager = ImageManager::gd();
        $this->coreImageAvailable = false;

        // Path to Swift tool
        $this->swiftToolPath = app_path('Services/CoreImage/ProofgenImageEnhancer.swift');

        // Check if we're on macOS and Swift tool exists
        if (PHP_OS_FAMILY === 'Darwin' && file_exists($this->swiftToolPath)) {
            $this->coreImageAvailable = $this->checkCoreImageAvailability();
        }
    }

    /**
     * Check if Core Image is available
     */
    protected function checkCoreImageAvailability(): bool
    {
        try {
            // Test Swift availability
            $testProcess = new Process(['swift', '--version']);
            $testProcess->run();

            if (! $testProcess->isSuccessful()) {
                Log::warning('CoreImageEnhancementService: Swift not available');

                return false;
            }

            // Test if our Swift tool can compile and run
            // Create a simple test request
            $testRequest = json_encode([
                'method' => 'basic_auto_levels',
                'inputPath' => '/dev/null',
                'outputPath' => '/dev/null',
                'parameters' => [],
            ]);

            // Try to start the process and send a test request
            $testProcess = new Process(['swift', $this->swiftToolPath]);
            $testProcess->setTimeout(10);
            $testProcess->setInput($testRequest."\nEXIT\n");
            $testProcess->run();

            $output = $testProcess->getOutput();

            // Check if we got the READY signal
            if (strpos($output, 'READY') === false) {
                Log::warning('CoreImageEnhancementService: Swift tool failed to start properly. Output: '.$output);
                if ($testProcess->getErrorOutput()) {
                    Log::warning('CoreImageEnhancementService: Error output: '.$testProcess->getErrorOutput());
                }

                return false;
            }

            Log::info('CoreImageEnhancementService: Core Image is available and working');

            return true;

        } catch (\Exception $e) {
            Log::warning('CoreImageEnhancementService: Failed to initialize - '.$e->getMessage());

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

            // Execute enhancement
            $response = $this->executeCoreImageEnhancement($request);

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
            case 'percentile_with_curve':
                $prepared['percentile_low'] = $prepared['percentile_low'] ?? 0.1;
                $prepared['percentile_high'] = $prepared['percentile_high'] ?? 99.9;
                break;

            case 'clahe':
                $prepared['clahe_clip_limit'] = $prepared['clahe_clip_limit'] ?? 2.0;
                $prepared['clahe_grid_size'] = $prepared['clahe_grid_size'] ?? 8.0;
                break;
        }

        return $prepared;
    }

    /**
     * Execute Core Image enhancement via Swift tool
     */
    protected function executeCoreImageEnhancement(array $request): array
    {
        // Always restart the process for each request to avoid stdin issues
        $this->stopSwiftProcess();
        $this->startSwiftProcess();

        try {
            // Verify process is running and has input stream
            if (! $this->swiftProcess->isRunning()) {
                throw new \Exception('Swift process is not running');
            }

            $input = $this->swiftProcess->getInput();
            if (! $input) {
                throw new \Exception('Swift process input stream is not available');
            }

            // Send request as JSON
            $json = json_encode($request)."\n";
            $input->write($json);

            // Read response with timeout
            $timeout = time() + 30; // 30 second timeout
            $output = '';

            while (time() < $timeout) {
                $incrementalOutput = $this->swiftProcess->getIncrementalOutput();
                if ($incrementalOutput) {
                    $output .= $incrementalOutput;

                    // Check if we have a complete JSON response
                    if (strpos($output, "\n") !== false) {
                        $lines = explode("\n", $output);
                        foreach ($lines as $line) {
                            if (trim($line) && $line !== 'READY') {
                                $response = json_decode(trim($line), true);
                                if ($response !== null) {
                                    // Stop the process after getting response
                                    $this->stopSwiftProcess();

                                    return $response;
                                }
                            }
                        }
                    }
                }

                usleep(10000); // 10ms
            }

            throw new \Exception('Timeout waiting for Core Image response');
        } catch (\Exception $e) {
            // Restart process on error
            $this->stopSwiftProcess();
            throw $e;
        }
    }

    /**
     * Start the Swift process
     */
    protected function startSwiftProcess(): void
    {
        $this->swiftProcess = new Process(['swift', $this->swiftToolPath]);
        $this->swiftProcess->setTimeout(null); // No timeout for long-running process
        $this->swiftProcess->setIdleTimeout(300); // 5 minute idle timeout

        // Start the process with pipes for stdin/stdout
        $this->swiftProcess->start(function ($type, $buffer) {
            if ($type === Process::ERR) {
                Log::debug('Swift process stderr: '.$buffer);
            }
        });

        // Wait for ready signal
        $timeout = time() + 10;
        $foundReady = false;

        while (time() < $timeout) {
            $output = $this->swiftProcess->getIncrementalOutput();
            $errorOutput = $this->swiftProcess->getIncrementalErrorOutput();

            if ($errorOutput) {
                Log::warning('Swift process stderr: '.$errorOutput);
            }

            if (strpos($output, 'READY') !== false) {
                $foundReady = true;
                break;
            }

            if (! $this->swiftProcess->isRunning()) {
                throw new \Exception('Swift process terminated unexpectedly. Exit code: '.$this->swiftProcess->getExitCode());
            }

            usleep(100000); // 100ms
        }

        if (! $foundReady) {
            $allOutput = $this->swiftProcess->getOutput();
            $allError = $this->swiftProcess->getErrorOutput();
            throw new \Exception('Swift process failed to start. Output: '.$allOutput.' Error: '.$allError);
        }

        Log::debug('Swift process started successfully');
    }

    /**
     * Stop the Swift process
     */
    protected function stopSwiftProcess(): void
    {
        if ($this->swiftProcess && $this->swiftProcess->isRunning()) {
            try {
                $input = $this->swiftProcess->getInput();
                if ($input) {
                    $input->write("EXIT\n");
                    $this->swiftProcess->wait(2); // Wait up to 2 seconds
                }
            } catch (\Exception $e) {
                // Input might be closed, just stop the process
                Log::debug('Could not send EXIT to Swift process: '.$e->getMessage());
            }

            if ($this->swiftProcess->isRunning()) {
                $this->swiftProcess->stop();
            }
        }

        $this->swiftProcess = null;
    }

    /**
     * Destructor - ensure Swift process is stopped
     */
    public function __destruct()
    {
        $this->stopSwiftProcess();
    }
}
