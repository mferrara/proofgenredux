<?php

namespace App\Console\Commands;

use App\Services\VersionService;
use Illuminate\Console\Command;

class VersionCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:version 
                            {--write : Write the current version to VERSION file}
                            {--clear-cache : Clear the version cache}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display or manage application version';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('clear-cache')) {
            VersionService::clearCache();
            $this->info('Version cache cleared.');
        }

        $version = VersionService::getVersion();

        if ($this->option('write')) {
            file_put_contents(base_path('VERSION'), $version);
            $this->info("Version {$version} written to VERSION file.");
            return Command::SUCCESS;
        }

        $this->info("Application Version: {$version}");
        
        // Show clean version too if different
        $cleanVersion = VersionService::getCleanVersion();
        if ($cleanVersion !== $version) {
            $this->info("Clean Version: {$cleanVersion}");
        }

        return Command::SUCCESS;
    }
}