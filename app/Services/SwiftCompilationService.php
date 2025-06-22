<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class SwiftCompilationService
{
    protected SwiftCompatibilityService $swiftCompatibilityService;

    protected array $swiftBinaries = [
        'ProofgenImageEnhancer' => [
            'source' => 'app/Services/CoreImage/ProofgenImageEnhancer.swift',
            'output' => 'storage/app/ProofgenImageEnhancer',
        ],
        'ProofgenImageEnhancerDaemon' => [
            'source' => 'app/Services/CoreImage/ProofgenImageEnhancerDaemon.swift',
            'output' => 'storage/app/ProofgenImageEnhancerDaemon',
        ],
    ];

    public function __construct(SwiftCompatibilityService $swiftCompatibilityService)
    {
        $this->swiftCompatibilityService = $swiftCompatibilityService;
    }

    /**
     * Compile all Swift binaries
     *
     * @return array Results of compilation with success status and messages
     */
    public function compileAll(): array
    {
        $results = [
            'success' => true,
            'binaries' => [],
            'errors' => [],
        ];

        // First check Swift compatibility
        $compatibility = $this->swiftCompatibilityService->checkCompatibility();
        if (!$compatibility['compatible']) {
            $results['success'] = false;
            $results['errors'][] = 'Swift is not compatible: ' . ($compatibility['error'] ?? 'Unknown error');
            return $results;
        }

        foreach ($this->swiftBinaries as $name => $paths) {
            $result = $this->compileBinary($name, $paths['source'], $paths['output']);
            $results['binaries'][$name] = $result;
            
            if (!$result['success']) {
                $results['success'] = false;
                $results['errors'][] = "Failed to compile {$name}: " . $result['error'];
            }
        }

        return $results;
    }

    /**
     * Compile a single Swift binary
     *
     * @param string $name Binary name for logging
     * @param string $sourcePath Relative path to Swift source file
     * @param string $outputPath Relative path to output binary
     * @return array Result with success status and message
     */
    public function compileBinary(string $name, string $sourcePath, string $outputPath): array
    {
        $result = [
            'name' => $name,
            'success' => false,
            'message' => '',
            'error' => null,
            'compilation_time' => 0,
        ];

        $startTime = microtime(true);

        try {
            $absoluteSourcePath = base_path($sourcePath);
            $absoluteOutputPath = base_path($outputPath);

            // Check if source file exists
            if (!file_exists($absoluteSourcePath)) {
                throw new \Exception("Source file not found: {$sourcePath}");
            }

            // Create output directory if it doesn't exist
            $outputDir = dirname($absoluteOutputPath);
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            // Remove existing binary if it exists
            if (file_exists($absoluteOutputPath)) {
                unlink($absoluteOutputPath);
            }

            // Compile the Swift binary with optimization
            $command = [
                'swiftc',
                '-O',
                '-o', $absoluteOutputPath,
                $absoluteSourcePath
            ];

            Log::info("Compiling Swift binary {$name}", [
                'command' => implode(' ', $command),
                'source' => $sourcePath,
                'output' => $outputPath
            ]);

            $process = Process::run($command);

            if ($process->successful()) {
                // Make the binary executable
                chmod($absoluteOutputPath, 0755);
                
                $result['success'] = true;
                $result['message'] = "Successfully compiled {$name}";
                $result['compilation_time'] = microtime(true) - $startTime;
                
                Log::info($result['message'], [
                    'compilation_time' => $result['compilation_time']
                ]);
            } else {
                throw new \Exception($process->errorOutput() ?: 'Compilation failed without error output');
            }

        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
            Log::error("Failed to compile Swift binary {$name}", [
                'error' => $e->getMessage(),
                'source' => $sourcePath,
                'output' => $outputPath
            ]);
        }

        return $result;
    }

    /**
     * Check if all Swift binaries exist and are executable
     *
     * @return array Status of each binary
     */
    public function checkBinariesStatus(): array
    {
        $status = [];

        foreach ($this->swiftBinaries as $name => $paths) {
            $absolutePath = base_path($paths['output']);
            $exists = file_exists($absolutePath);
            $executable = $exists && is_executable($absolutePath);
            $modifiedTime = $exists ? filemtime($absolutePath) : null;
            
            $status[$name] = [
                'exists' => $exists,
                'executable' => $executable,
                'path' => $paths['output'],
                'modified_time' => $modifiedTime,
                'modified_human' => $modifiedTime ? date('Y-m-d H:i:s', $modifiedTime) : null,
            ];
        }

        return $status;
    }

    /**
     * Get information about Swift binaries
     *
     * @return array
     */
    public function getBinaryInfo(): array
    {
        return $this->swiftBinaries;
    }

    /**
     * Clean up compiled binaries
     *
     * @return array Results of cleanup
     */
    public function cleanBinaries(): array
    {
        $results = [
            'success' => true,
            'removed' => [],
            'errors' => [],
        ];

        foreach ($this->swiftBinaries as $name => $paths) {
            $absolutePath = base_path($paths['output']);
            
            if (file_exists($absolutePath)) {
                try {
                    unlink($absolutePath);
                    $results['removed'][] = $name;
                    Log::info("Removed Swift binary: {$name}");
                } catch (\Exception $e) {
                    $results['success'] = false;
                    $results['errors'][] = "Failed to remove {$name}: " . $e->getMessage();
                    Log::error("Failed to remove Swift binary {$name}", [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        return $results;
    }
}