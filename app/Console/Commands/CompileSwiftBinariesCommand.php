<?php

namespace App\Console\Commands;

use App\Services\SwiftCompilationService;
use Illuminate\Console\Command;

class CompileSwiftBinariesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'swift:compile 
                            {--clean : Remove existing binaries before compiling}
                            {--status : Show current binary status without compiling}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Compile Swift binaries for Core Image enhancement';

    /**
     * Execute the console command.
     */
    public function handle(SwiftCompilationService $compilationService): int
    {
        // If status flag is set, just show current status
        if ($this->option('status')) {
            return $this->showBinaryStatus($compilationService);
        }

        // If clean flag is set, remove existing binaries first
        if ($this->option('clean')) {
            $this->info('Cleaning existing Swift binaries...');
            $cleanResult = $compilationService->cleanBinaries();
            
            if (!empty($cleanResult['removed'])) {
                foreach ($cleanResult['removed'] as $removed) {
                    $this->info("  ✓ Removed: {$removed}");
                }
            }
            
            if (!empty($cleanResult['errors'])) {
                foreach ($cleanResult['errors'] as $error) {
                    $this->error("  ✗ {$error}");
                }
            }
            
            $this->newLine();
        }

        $this->info('Compiling Swift binaries...');
        $this->newLine();

        // Show current Swift version
        $compatibility = app(\App\Services\SwiftCompatibilityService::class)->checkCompatibility();
        if ($compatibility['version']) {
            $this->info("Swift version: {$compatibility['version']}");
            $this->newLine();
        }

        // Compile all binaries
        $results = $compilationService->compileAll();

        // Display results for each binary
        foreach ($results['binaries'] as $name => $result) {
            if ($result['success']) {
                $this->info("✓ {$name}");
                $this->line("  {$result['message']}");
                $this->line("  Compilation time: " . number_format($result['compilation_time'], 2) . "s");
            } else {
                $this->error("✗ {$name}");
                $this->error("  {$result['error']}");
            }
            $this->newLine();
        }

        // Show overall summary
        if ($results['success']) {
            $this->info('All Swift binaries compiled successfully!');
            
            // Check if daemon needs to be restarted
            $daemonService = app(\App\Services\CoreImageDaemonService::class);
            if ($daemonService->isCoreImageAvailable()) {
                $this->newLine();
                $this->warn('Note: Core Image daemon is running. You may want to restart it to use the new binaries:');
                $this->info('  php artisan coreimage:daemon restart');
            }
            
            return Command::SUCCESS;
        } else {
            $this->error('Some binaries failed to compile.');
            
            if (!empty($results['errors'])) {
                $this->newLine();
                $this->error('Errors:');
                foreach ($results['errors'] as $error) {
                    $this->error("  - {$error}");
                }
            }
            
            return Command::FAILURE;
        }
    }

    /**
     * Show the current status of Swift binaries
     */
    protected function showBinaryStatus(SwiftCompilationService $compilationService): int
    {
        $this->info('Swift Binary Status');
        $this->info('==================');
        $this->newLine();

        $status = $compilationService->checkBinariesStatus();
        $allExist = true;

        foreach ($status as $name => $info) {
            if ($info['exists']) {
                $this->info("✓ {$name}");
                $this->line("  Path: {$info['path']}");
                $this->line("  Executable: " . ($info['executable'] ? 'Yes' : 'No'));
                $this->line("  Modified: {$info['modified_human']}");
            } else {
                $this->warn("✗ {$name}");
                $this->line("  Path: {$info['path']}");
                $this->line("  Status: Not compiled");
                $allExist = false;
            }
            $this->newLine();
        }

        if (!$allExist) {
            $this->warn('Some binaries are missing. Run "php artisan swift:compile" to compile them.');
        }

        // Check daemon status
        try {
            $daemonService = app(\App\Services\CoreImageDaemonService::class);
            $this->info('Core Image Daemon Status');
            $this->info('=======================');
            
            if ($daemonService->isCoreImageAvailable()) {
                $this->info('✓ Daemon is running');
                
                $pidFile = storage_path('core-image-daemon.pid');
                if (file_exists($pidFile)) {
                    $pid = trim(file_get_contents($pidFile));
                    $this->line("  PID: {$pid}");
                }
            } else {
                $this->warn('✗ Daemon is not running');
                $this->line('  Start with: php artisan coreimage:daemon start');
            }
        } catch (\Exception $e) {
            $this->error('Could not check daemon status: ' . $e->getMessage());
        }

        return Command::SUCCESS;
    }
}