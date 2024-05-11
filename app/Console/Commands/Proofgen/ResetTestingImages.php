<?php

namespace App\Console\Commands\Proofgen;

use App\Proofgen\Utility;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ResetTestingImages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'proofgen:reset-testing-images';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset the testing images';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Copy the images from the testing_images filesystem to the fullsize filesystem
        $this->info('Copying testing images to fullsize...');

        // Get all files and directories in the testing_images filesystem
        $testing_images = Utility::getContentsOfPath('/', true, 'testing_images');

        // Copy each file from the testing_images filesystem to the fullsize filesystem maintaining their original filenames and directories
        foreach ($testing_images['images'] as $file) {
            $contents = Storage::disk('testing_images')->get($file->path());
            Storage::disk('fullsize')->put($file->path(), $contents);
            $this->info('copied file '.$file->path());
        }
    }
}
