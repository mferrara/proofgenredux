<?php

namespace App\Console\Commands;

use App\Services\PathResolver;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MigrateProofsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'proofs:migrate
                            {--base-path= : Specify the base path where show directories are located (default: fullsize_home_dir from config)}
                            {--dry-run : Run without making any changes, just report what would be done}
                            {--move : Move files instead of copying them (deletes originals after successful copy and removes empty directories)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Detect and migrate photo proofs and web images from the old directory structure to the new one';

    /**
     * Statistics for the migration process.
     *
     * @var array
     */
    protected $stats = [
        'shows_scanned' => 0,
        'shows_with_old_structure' => 0,
        'classes_scanned' => 0,
        'classes_with_old_structure' => 0,
        'proof_files_found' => 0,
        'web_files_found' => 0,
        'files_migrated' => 0,
        'errors' => 0,
    ];

    /**
     * The path resolver instance.
     *
     * @var \App\Services\PathResolver
     */
    protected $pathResolver;

    /**
     * Create a new command instance.
     */
    public function __construct(PathResolver $pathResolver)
    {
        parent::__construct();
        $this->pathResolver = $pathResolver;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Get options
        $dryRun = $this->option('dry-run');
        $moveFiles = $this->option('move');

        // Get base path from command line or config
        try {
            $basePath = $this->option('base-path') ?? config('proofgen.fullsize_home_dir');
        } catch (\Exception $e) {
            // If not found in config, fall back to .env
            $basePath = $this->option('base-path') ?? env('FULLSIZE_HOME_DIR');
        }

        if (! $basePath || ! File::isDirectory($basePath)) {
            $this->error("Error: Invalid base path: {$basePath}");

            return 1;
        }

        $this->info("Starting migration check with base path: {$basePath}");

        if ($dryRun) {
            $this->comment('DRY RUN MODE: No files will be modified');
        }

        $this->info($moveFiles
            ? 'MOVE MODE: Files will be moved instead of copied'
            : 'COPY MODE: Original files will be preserved');

        // Create destination directories if they don't exist
        if (! $dryRun) {
            $this->createDestinationDirectories($basePath);
        }

        // Process all show directories
        $this->processShowDirectories($basePath, $dryRun, $moveFiles);

        // Output summary
        $this->outputSummary($dryRun);

        // Write a log file
        $this->writeLogFile();

        return 0;
    }

    /**
     * Create the destination directories for proofs and web images.
     */
    protected function createDestinationDirectories(string $basePath): void
    {
        $proofsDir = rtrim($basePath, '/').'/proofs';
        $webImagesDir = rtrim($basePath, '/').'/web_images';

        if (! File::isDirectory($proofsDir)) {
            File::makeDirectory($proofsDir, 0755, true);
            $this->info("Created directory: {$proofsDir}");
        }

        if (! File::isDirectory($webImagesDir)) {
            File::makeDirectory($webImagesDir, 0755, true);
            $this->info("Created directory: {$webImagesDir}");
        }
    }

    /**
     * Process all show directories in the base path.
     */
    protected function processShowDirectories(string $basePath, bool $dryRun, bool $moveFiles): void
    {
        $showDirectories = File::directories($basePath);
        $bar = $this->output->createProgressBar(count($showDirectories));
        $bar->start();

        foreach ($showDirectories as $showPath) {
            $showName = basename($showPath);
            $this->stats['shows_scanned']++;

            $this->newLine();
            $this->info("Scanning show: {$showName}");

            // Skip the top-level 'proofs' and 'web_images' directories
            if (in_array($showName, ['proofs', 'web_images'])) {
                $this->line("  Skipping directory: {$showName} (reserved name)");

                continue;
            }

            $hasOldStructure = false;

            // Process classes within this show
            $hasOldStructure = $this->processClassDirectories($showPath, $showName, $basePath, $dryRun, $moveFiles);

            if ($hasOldStructure) {
                $this->stats['shows_with_old_structure']++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
    }

    /**
     * Process all class directories within a show.
     *
     * @return bool Whether this show has any classes with old structure
     */
    protected function processClassDirectories(string $showPath, string $showName, string $basePath, bool $dryRun, bool $moveFiles): bool
    {
        $hasOldStructure = false;
        $classDirectories = File::directories($showPath);

        foreach ($classDirectories as $classPath) {
            $className = basename($classPath);
            $this->stats['classes_scanned']++;

            // Skip the 'originals', 'proofs', and 'web_images' directories if they appear at this level
            if (in_array($className, ['originals', 'proofs', 'web_images'])) {
                continue;
            }

            // Check and migrate proofs
            $oldProofPath = "{$classPath}/proofs";
            if (File::isDirectory($oldProofPath)) {
                $hasOldStructure = true;
                $this->stats['classes_with_old_structure']++;

                $this->line("  Found old structure in class: {$className} (proofs)");

                // Get the new path using the path resolver
                $relativeProofsPath = $this->pathResolver->getProofsPath($showName, $className);
                $newProofsPath = $this->pathResolver->getAbsolutePath($relativeProofsPath, $basePath);

                // Migrate small thumbnails (_thm.jpg)
                $this->migrateFiles(
                    $oldProofPath,
                    $newProofsPath,
                    '*_thm.jpg',
                    $dryRun,
                    $moveFiles,
                    'proof_files_found'
                );

                // Migrate large thumbnails (_std.jpg)
                $this->migrateFiles(
                    $oldProofPath,
                    $newProofsPath,
                    '*_std.jpg',
                    $dryRun,
                    $moveFiles,
                    'proof_files_found'
                );

                // Attempt to remove any common hidden files that might trip us up in detecting an otherwise empty
                // directory after all the files are moved
                $hiddenFiles = glob("{$oldProofPath}/.*");
                foreach ($hiddenFiles as $hiddenFile) {
                    if (is_file($hiddenFile)) {
                        File::delete($hiddenFile);
                        $this->line("    Removed hidden file: {$hiddenFile}");
                    }
                }
            }

            // If we're on a 'move' run and the path is now empty, remove it
            if (! $dryRun && $moveFiles && File::isDirectory($oldProofPath)) {
                $remainingFiles = array_filter(glob("{$oldProofPath}/*"), 'is_file');
                if (count($remainingFiles) == 0) {
                    File::deleteDirectory($oldProofPath);
                    $this->line("    Removed empty directory: {$oldProofPath}");
                }
            }

            // Check and migrate web images
            $oldWebPath = "{$classPath}/web_images";
            if (File::isDirectory($oldWebPath)) {
                $hasOldStructure = true;
                if (! isset($this->classesWithOldStructure[$className])) {
                    $this->stats['classes_with_old_structure']++;
                }

                $this->line("  Found old structure in class: {$className} (web_images)");

                // Get the new path using the path resolver
                $relativeWebPath = $this->pathResolver->getWebImagesPath($showName, $className);
                $newWebPath = $this->pathResolver->getAbsolutePath($relativeWebPath, $basePath);

                $this->migrateFiles(
                    $oldWebPath,
                    $newWebPath,
                    '*_web.jpg',
                    $dryRun,
                    $moveFiles,
                    'web_files_found'
                );
            }
        }

        return $hasOldStructure;
    }

    /**
     * Migrate files from old path to new path.
     *
     * @param  string  $statsKey  Key to use for tracking statistics
     */
    protected function migrateFiles(string $oldPath, string $newPath, string $pattern, bool $dryRun, bool $moveFiles, string $statsKey): void
    {
        // Get all files matching the pattern
        $files = glob("{$oldPath}/{$pattern}");
        $this->stats[$statsKey] += count($files);

        $this->line('    Found '.count($files).' '.$pattern.' files');

        // Create the destination directory if needed
        if (! $dryRun && ! File::isDirectory($newPath)) {
            File::makeDirectory($newPath, 0755, true);
        }

        // Migrate each file
        foreach ($files as $file) {
            $fileName = basename($file);
            $newFilePath = "{$newPath}/{$fileName}";

            if (! $dryRun) {
                try {
                    if ($moveFiles) {
                        if (File::move($file, $newFilePath)) {
                            $this->stats['files_migrated']++;
                        } else {
                            $this->error("    Error moving: {$fileName}");
                            $this->stats['errors']++;
                        }
                    } else {
                        if (File::copy($file, $newFilePath)) {
                            $this->stats['files_migrated']++;
                        } else {
                            $this->error("    Error copying: {$fileName}");
                            $this->stats['errors']++;
                        }
                    }
                } catch (Exception $e) {
                    $this->error("    Error with file {$fileName}: ".$e->getMessage());
                    $this->stats['errors']++;
                }
            } else {
                // In dry run mode, just count the file
                $this->stats['files_migrated']++;
            }
        }

        // If in move mode and all files were moved successfully, remove the empty directory
        if (! $dryRun && $moveFiles && count($files) > 0 && count($files) == $this->stats['files_migrated'] - $this->stats['errors']) {
            try {
                // Check if directory is empty (except for subdirectories which shouldn't exist in this context)
                $remainingFiles = array_filter(glob("{$oldPath}/*"), 'is_file');
                if (count($remainingFiles) == 0) {
                    if (File::deleteDirectory($oldPath)) {
                        $this->line("    Removed empty directory: {$oldPath}");
                    } else {
                        $this->error("    Could not remove directory: {$oldPath}");
                    }
                }
            } catch (Exception $e) {
                $this->error("    Error removing directory {$oldPath}: ".$e->getMessage());
            }
        }
    }

    /**
     * Output a summary of the migration process.
     */
    protected function outputSummary(bool $dryRun): void
    {
        $this->info("\n============ Migration Summary ============");
        $this->line("Shows scanned: {$this->stats['shows_scanned']}");
        $this->line("Shows with old structure: {$this->stats['shows_with_old_structure']}");
        $this->line("Classes scanned: {$this->stats['classes_scanned']}");
        $this->line("Classes with old structure: {$this->stats['classes_with_old_structure']}");
        $this->line("Proof files found: {$this->stats['proof_files_found']}");
        $this->line("Web image files found: {$this->stats['web_files_found']}");
        $this->line('Files '.($dryRun ? 'that would be' : 'successfully')." migrated: {$this->stats['files_migrated']}");
        $this->line("Errors encountered: {$this->stats['errors']}");

        if ($dryRun) {
            $this->comment("\nThis was a dry run. Run without --dry-run to perform the actual migration.");
        }
    }

    /**
     * Write a log file with the migration statistics.
     */
    protected function writeLogFile(): void
    {
        $logDir = storage_path('logs');
        $logFile = $logDir.'/migration_'.date('Y-m-d_H-i-s').'.log';

        File::put($logFile, json_encode($this->stats, JSON_PRETTY_PRINT));
        $this->info("\nLog file written to: {$logFile}");
    }
}
