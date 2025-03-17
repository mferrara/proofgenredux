<?php

namespace App\Console\Commands;

use App\Services\SampleImagesService;
use Illuminate\Console\Command;

class DownloadSampleImagesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'proofgen:download-samples';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Download sample images for testing from S3-compatible bucket';

    /**
     * Execute the console command.
     */
    public function handle(SampleImagesService $sampleImagesService)
    {
        try {
            $this->info('Checking if sample images exist...');
            
            if ($sampleImagesService->hasSampleImages()) {
                if ($this->confirm('Sample images already exist. Do you want to download them again?', false)) {
                    $this->performDownload($sampleImagesService);
                } else {
                    $this->info('Download skipped.');
                    return 0;
                }
            } else {
                $this->performDownload($sampleImagesService);
            }
            
            return 0;
        } catch (\Exception $e) {
            $this->error('Failed to download sample images: ' . $e->getMessage());
            return 1;
        }
    }
    
    /**
     * Perform the actual download with progress feedback
     */
    protected function performDownload(SampleImagesService $sampleImagesService): void
    {
        $this->info("Downloading sample images from bucket...");
        
        // Display S3 connection info
        $this->comment("Using bucket: " . env('SAMPLE_IMAGES_S3_BUCKET'));
        $this->comment("Endpoint: " . env('SAMPLE_IMAGES_S3_ENDPOINT', 'default S3'));
        
        // Start a progress bar with indeterminate progress
        $this->output->write("Downloading... ");
        
        try {
            // Perform the download
            $startTime = microtime(true);
            $result = $sampleImagesService->downloadSampleImages();
            $endTime = microtime(true);
            
            $this->info("Done! (" . round($endTime - $startTime, 2) . " seconds)");
            
            $this->info("Sample images successfully downloaded");
        } catch (\Exception $e) {
            $this->error("Failed");
            throw $e;
        }
    }
}