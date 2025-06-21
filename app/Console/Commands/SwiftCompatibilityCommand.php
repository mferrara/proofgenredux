<?php

namespace App\Console\Commands;

use App\Services\SwiftCompatibilityService;
use Illuminate\Console\Command;

class SwiftCompatibilityCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'swift:check {--force : Force re-check, ignoring cache}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check Swift compatibility for Core Image enhancement';

    /**
     * Execute the console command.
     */
    public function handle(SwiftCompatibilityService $service): int
    {
        $force = $this->option('force');
        
        if ($force) {
            $this->info('Forcing fresh Swift compatibility check...');
        } else {
            $this->info('Checking Swift compatibility...');
        }
        
        $result = $service->checkCompatibility($force);
        
        $this->newLine();
        
        // Display platform info
        $this->info('Platform: ' . $result['platform']);
        
        if ($result['platform'] !== 'Darwin') {
            $this->error('✗ Core Image enhancement requires macOS');
            return Command::FAILURE;
        }
        
        // Display Swift availability
        if (!$result['swift_available']) {
            $this->error('✗ Swift not found');
            $this->newLine();
            $this->warn('To install Swift on macOS:');
            $this->info('  1. Install Xcode from the App Store, OR');
            $this->info('  2. Install Xcode Command Line Tools:');
            $this->info('     xcode-select --install');
            $this->newLine();
            $this->info('After installation, verify with:');
            $this->info('  swift --version');
            
            return Command::FAILURE;
        }
        
        // Display version info
        if ($result['version']) {
            $this->info('Swift version: ' . $result['version']);
            $this->info('Required version: ' . $result['minimum_version'] . ' or higher');
        }
        
        // Display compatibility status
        if ($result['compatible']) {
            $this->newLine();
            $this->info('✓ Swift is compatible for Core Image enhancement');
            
            // Check if daemon is running
            try {
                $daemonService = app(\App\Services\CoreImageDaemonService::class);
                if ($daemonService->isCoreImageAvailable()) {
                    $this->info('✓ Core Image daemon is running');
                } else {
                    $this->warn('Core Image daemon is not running');
                    $this->info('Start it with: php artisan coreimage:daemon start');
                }
            } catch (\Exception $e) {
                $this->warn('Could not check daemon status: ' . $e->getMessage());
            }
            
            return Command::SUCCESS;
        } else {
            $this->newLine();
            $this->error('✗ Swift compatibility check failed');
            if ($result['error']) {
                $this->error('Error: ' . $result['error']);
            }
            
            return Command::FAILURE;
        }
    }
}