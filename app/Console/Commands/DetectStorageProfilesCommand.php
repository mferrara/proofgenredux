<?php

namespace App\Console\Commands;

use App\Services\Storage\ProfileAutoDetector;
use Illuminate\Console\Command;

class DetectStorageProfilesCommand extends Command
{
    protected $signature = 'proofgen:detect-storage-profiles';

    protected $description = 'Detect storage profiles from PROFILE_* environment variables';

    public function handle(ProfileAutoDetector $detector): int
    {
        $profiles = $detector->detectFromEnv();

        if (empty($profiles)) {
            $this->info('No storage profiles detected.');

            return Command::SUCCESS;
        }

        $this->table(
            ['ID', 'Label', 'Driver', 'Active', 'Writable'],
            array_map(fn ($profile) => [
                $profile->id,
                $profile->label,
                $profile->driver,
                $profile->is_active ? 'yes' : 'no',
                $profile->is_writable ? 'yes' : 'no',
            ], $profiles),
        );

        return Command::SUCCESS;
    }
}
