<?php

namespace App\Console\Commands;

use App\Services\SampleImagesService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class UploadSampleImagesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'proofgen:upload-samples
                            {--path= : Source directory to upload from (defaults to sample_images disk)}
                            {--no-overwrite : Don\'t overwrite existing files in bucket}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Upload sample images to S3-compatible bucket';

    /**
     * Execute the console command.
     */
    public function handle(SampleImagesService $sampleImagesService)
    {
        $sourcePath = $this->option('path');
        $overwrite = !$this->option('no-overwrite');
        
        // If no path is provided, use the sample_images disk path
        if (!$sourcePath) {
            $sourcePath = null; // null means use the sample_images disk
            $this->info('Using default sample_images directory: ' . storage_path('sample_images'));
        } else {
            $this->info('Using custom source directory: ' . $sourcePath);
        }
        
        // Display S3 connection info
        $this->comment("Using bucket: " . env('SAMPLE_IMAGES_S3_BUCKET'));
        $this->comment("Endpoint: " . env('SAMPLE_IMAGES_S3_ENDPOINT', 'default S3'));
        $this->comment("Overwrite existing files: " . ($overwrite ? 'Yes' : 'No'));
        
        // Confirm the upload
        if (!$this->confirm('Ready to upload sample images?', true)) {
            $this->info('Upload cancelled.');
            return 0;
        }
        
        try {
            $this->output->write("Uploading... ");
            
            // Start timing the operation
            $startTime = microtime(true);
            
            // Perform the upload
            $result = $sampleImagesService->uploadSampleImages($sourcePath, $overwrite);
            
            $endTime = microtime(true);
            $this->info("Done! (" . round($endTime - $startTime, 2) . " seconds)");
            
            // Display results
            $this->table(
                ['Total Files', 'Uploaded', 'Skipped', 'Failed'],
                [[
                    $result['total'],
                    $result['uploaded'],
                    $result['skipped'],
                    $result['failed']
                ]]
            );
            
            if ($result['failed'] > 0) {
                $this->warn("Some files failed to upload. Check the logs for details.");
                return 1;
            }
            
            $this->info("Sample images successfully uploaded to bucket.");
            return 0;
        } catch (\Exception $e) {
            $this->error('Failed to upload sample images: ' . $e->getMessage());
            return 1;
        }
    }
}