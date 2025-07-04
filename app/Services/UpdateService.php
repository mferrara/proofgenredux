<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class UpdateService
{
    protected string $backupPath;

    protected int $maxBackups = 5;

    protected array $excludeFromBackup = [
        'vendor',
        'node_modules',
        'storage/framework',
        'storage/logs',
        'storage/cache',
        'storage/sample_images',
        'storage/logs',
        'storage/app/public',
        'backups',
        '.git',
        '.env',
    ];

    public function __construct()
    {
        $this->backupPath = base_path('backups');
    }

    /**
     * Get the current version from git tags
     */
    public function getCurrentVersion(): ?string
    {
        $result = Process::path(base_path())->run('git describe --tags --abbrev=0 2>/dev/null');

        if ($result->successful() && ! empty($result->output())) {
            return trim($result->output());
        }

        // If no tags, get current commit hash
        $result = Process::path(base_path())->run('git rev-parse --short HEAD');

        return $result->successful() ? 'commit-'.trim($result->output()) : null;
    }

    /**
     * Check for available updates by comparing with remote tags
     */
    public function checkForUpdates(): array
    {
        // Fetch latest tags from remote
        Process::path(base_path())->run('git fetch --tags');

        $currentVersion = $this->getCurrentVersion();
        $latestVersion = $this->getLatestRemoteVersion();

        return [
            'current_version' => $currentVersion,
            'latest_version' => $latestVersion,
            'update_available' => $this->isUpdateAvailable($currentVersion, $latestVersion),
        ];
    }

    /**
     * Get the latest version from remote
     */
    protected function getLatestRemoteVersion(): ?string
    {
        $result = Process::path(base_path())->run('git describe --tags --abbrev=0 $(git rev-list --tags --max-count=1) 2>/dev/null');

        if ($result->successful() && ! empty($result->output())) {
            return trim($result->output());
        }

        // If no tags, get latest remote commit
        $result = Process::path(base_path())->run('git rev-parse --short origin/main');

        return $result->successful() ? 'commit-'.trim($result->output()) : null;
    }

    /**
     * Check if an update is available
     */
    protected function isUpdateAvailable(?string $current, ?string $latest): bool
    {
        if (! $current || ! $latest) {
            return false;
        }

        // If both are commits, compare them
        if (str_starts_with($current, 'commit-') && str_starts_with($latest, 'commit-')) {
            return $current !== $latest;
        }

        // If one is a tag and the other is a commit, update is available
        if (str_starts_with($current, 'commit-') || str_starts_with($latest, 'commit-')) {
            return true;
        }

        // Compare semantic versions
        return version_compare($current, $latest, '<');
    }

    /**
     * Find the composer binary path
     * @return array|null Returns array of command parts or null if not found
     */
    protected function findComposerBinary(): ?array
    {
        // First check if there's a composer.phar in the project root (bundled with app)
        if (file_exists(base_path('composer.phar'))) {
            // Make sure PHP binary is available
            $phpBinary = config('proofgen.php_binary_path', 'php');
            // Return as array to handle paths with spaces
            return [$phpBinary, 'composer.phar'];
        }

        // Fallback: check if composer is in PATH
        $result = Process::run('which composer');
        if ($result->successful() && ! empty(trim($result->output()))) {
            return [trim($result->output())];
        }

        // Check common locations
        $commonPaths = [
            '/usr/local/bin/composer',
            '/opt/homebrew/bin/composer',
            '/usr/bin/composer',
            $_SERVER['HOME'].'/.composer/composer',
            $_SERVER['HOME'].'/.composer/vendor/bin/composer',
        ];

        foreach ($commonPaths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return [$path];
            }
        }

        return null;
    }

    /**
     * Perform the update process
     */
    public function performUpdate(): array
    {
        $steps = [];
        $success = true;
        $error = null;

        try {
            // Step 0: Check for composer
            $steps[] = 'Checking for composer...';
            $composerBinary = $this->findComposerBinary();
            if (! $composerBinary) {
                throw new \Exception('Composer not found. Please ensure composer is installed and accessible in PATH or common locations.');
            }
            $steps[] = "Found composer: " . implode(' ', $composerBinary);

            // Step 1: Stop Horizon
            $steps[] = 'Stopping Horizon...';
            $this->stopHorizon();

            // Step 2: Create backup
            $steps[] = 'Creating backup...';
            $backupDir = $this->createBackup();
            $steps[] = "Backup created: {$backupDir}";

            // Step 3: Pull latest changes
            $steps[] = 'Pulling latest changes...';
            $pullResult = Process::path(base_path())->timeout(120)->run('git pull origin main --tags');
            if (! $pullResult->successful()) {
                throw new \Exception('Git pull failed: '.$pullResult->errorOutput());
            }

            // Step 4: Checkout latest tag if available
            $latestTag = $this->getLatestRemoteVersion();
            if ($latestTag && ! str_starts_with($latestTag, 'commit-')) {
                $steps[] = "Checking out version {$latestTag}...";
                $checkoutResult = Process::path(base_path())->run("git checkout {$latestTag}");
                if (! $checkoutResult->successful()) {
                    throw new \Exception('Git checkout failed: '.$checkoutResult->errorOutput());
                }
            }

            // Step 5: Install composer dependencies
            $steps[] = 'Installing composer dependencies...';
            // composerBinary is already an array, just add the arguments
            $composerCommand = array_merge($composerBinary, ['install', '--no-dev', '--optimize-autoloader']);
            $composerResult = Process::path(base_path())->timeout(300)->run($composerCommand);
            if (! $composerResult->successful()) {
                throw new \Exception('Composer install failed: '.$composerResult->errorOutput());
            }

            // Step 6: Run migrations
            $steps[] = 'Running migrations...';
            $migrateResult = Process::path(base_path())->run('php artisan migrate --force');
            if (! $migrateResult->successful()) {
                throw new \Exception('Migrations failed: '.$migrateResult->errorOutput());
            }

            // Step 7: Build frontend assets
            $steps[] = 'Building frontend assets...';
            $npmResult = Process::path(base_path())->timeout(300)->run('npm ci && npm run build');
            if (! $npmResult->successful()) {
                throw new \Exception('NPM build failed: '.$npmResult->errorOutput());
            }

            // Step 8: Compile Swift binaries if on macOS
            if (PHP_OS_FAMILY === 'Darwin') {
                $steps[] = 'Checking Swift compatibility...';
                $swiftCompatibility = app(\App\Services\SwiftCompatibilityService::class)->checkCompatibility();
                
                if ($swiftCompatibility['compatible']) {
                    $steps[] = 'Compiling Swift binaries...';
                    $swiftCompilationService = app(\App\Services\SwiftCompilationService::class);
                    $compilationResult = $swiftCompilationService->compileAll();
                    
                    if ($compilationResult['success']) {
                        $steps[] = 'Swift binaries compiled successfully';
                        
                        // Step 9: Restart Core Image daemon
                        $steps[] = 'Restarting Core Image daemon...';
                        $daemonService = app(\App\Services\CoreImageDaemonService::class);
                        
                        // Stop the daemon if it's running
                        if ($daemonService->isCoreImageAvailable()) {
                            $daemonService->stopDaemon();
                            sleep(1); // Give it a moment to stop
                        }
                        
                        // Start the daemon
                        if ($daemonService->startDaemon()) {
                            sleep(2); // Wait for daemon to start
                            if ($daemonService->isCoreImageAvailable()) {
                                $steps[] = 'Core Image daemon restarted successfully';
                            } else {
                                Log::warning('Core Image daemon failed to start after compilation');
                                $steps[] = 'Warning: Core Image daemon failed to start';
                            }
                        } else {
                            Log::warning('Failed to start Core Image daemon');
                            $steps[] = 'Warning: Failed to start Core Image daemon';
                        }
                    } else {
                        Log::warning('Swift compilation failed during update', ['errors' => $compilationResult['errors']]);
                        $steps[] = 'Warning: Swift compilation failed - ' . implode(', ', $compilationResult['errors']);
                    }
                } else {
                    $steps[] = 'Skipping Swift compilation (Swift not compatible)';
                }
            }

            // Step 10: Clear caches
            $steps[] = 'Clearing caches...';
            Process::path(base_path())->run('php artisan config:clear');
            Process::path(base_path())->run('php artisan route:clear');
            Process::path(base_path())->run('php artisan view:clear');
            Process::path(base_path())->run('php artisan cache:clear');

            // Step 11: Cache config for production
            $steps[] = 'Optimizing application...';
            Process::path(base_path())->run('php artisan config:cache');
            Process::path(base_path())->run('php artisan route:cache');
            Process::path(base_path())->run('php artisan view:cache');

            // Step 12: Restart Horizon
            $steps[] = 'Starting Horizon...';
            $this->startHorizon();

            $steps[] = 'Update completed successfully!';

        } catch (\Exception $e) {
            $success = false;
            $error = $e->getMessage();
            $steps[] = 'Error: '.$error;
            Log::error('Update failed', ['error' => $error]);
        }

        // Clean up old backups
        $this->cleanupOldBackups();

        return [
            'success' => $success,
            'steps' => $steps,
            'error' => $error,
            'backup_dir' => $backupDir ?? null,
        ];
    }

    /**
     * Create a backup of the current application
     */
    protected function createBackup(): string
    {
        $timestamp = Carbon::now()->format('Y-m-d_H-i-s');
        $currentVersion = $this->getCurrentVersion() ?: 'unknown';
        $backupDir = $this->backupPath.'/'.$timestamp.'_'.$currentVersion;

        // Create backup directory
        File::makeDirectory($backupDir, 0755, true, true);

        // Use rsync to copy files, excluding certain directories
        $excludes = array_map(fn ($dir) => "--exclude='{$dir}'", $this->excludeFromBackup);
        $excludeString = implode(' ', $excludes);

        $rsyncCommand = "rsync -a {$excludeString} ".base_path().'/ '.$backupDir.'/';
        Process::timeout(300)->run($rsyncCommand);

        return $backupDir;
    }

    /**
     * Clean up old backups, keeping only the most recent ones
     */
    protected function cleanupOldBackups(): void
    {
        if (! File::exists($this->backupPath)) {
            return;
        }

        $backups = collect(File::directories($this->backupPath))
            ->sortByDesc(fn ($path) => File::lastModified($path))
            ->values();

        // Remove old backups beyond the limit
        $backups->slice($this->maxBackups)->each(function ($backup) {
            File::deleteDirectory($backup);
            Log::info('Deleted old backup: '.$backup);
        });
    }

    /**
     * Stop Horizon
     */
    protected function stopHorizon(): void
    {
        Process::path(base_path())->run('php artisan horizon:terminate');
        sleep(5); // Give Horizon time to gracefully shut down
    }

    /**
     * Start Horizon
     */
    protected function startHorizon(): void
    {
        // Start Horizon in the background
        Process::path(base_path())->run('nohup php artisan horizon > /dev/null 2>&1 &');
    }

    /**
     * Get list of available backups
     */
    public function getBackups(): array
    {
        if (! File::exists($this->backupPath)) {
            return [];
        }

        return collect(File::directories($this->backupPath))
            ->map(function ($path) {
                $name = basename($path);
                $parts = explode('_', $name, 3);

                return [
                    'path' => $path,
                    'name' => $name,
                    'date' => Carbon::parse($parts[0].' '.str_replace('-', ':', $parts[1]))->format('Y-m-d H:i:s'),
                    'version' => $parts[2] ?? 'unknown',
                    'size' => $this->getDirectorySize($path),
                ];
            })
            ->sortByDesc('date')
            ->values()
            ->all();
    }

    /**
     * Get directory size in human readable format
     */
    protected function getDirectorySize(string $path): string
    {
        $result = Process::run("du -sh '{$path}' | cut -f1");

        return $result->successful() ? trim($result->output()) : 'Unknown';
    }
}
