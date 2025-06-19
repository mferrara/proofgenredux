<?php

namespace App\Console\Commands;

use App\Services\SampleImagesService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ListSampleImagesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'proofgen:list-samples
                            {--source=both : Source to list from (local, bucket, or both)}
                            {--format=table : Output format (table or json)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List sample images available locally and/or in S3-compatible bucket';

    /**
     * Execute the console command.
     */
    public function handle(SampleImagesService $sampleImagesService)
    {
        $source = $this->option('source');
        $format = $this->option('format');

        if (! in_array($source, ['local', 'bucket', 'both'])) {
            $this->error("Invalid source option. Must be 'local', 'bucket', or 'both'.");

            return 1;
        }

        if (! in_array($format, ['table', 'json'])) {
            $this->error("Invalid format option. Must be 'table' or 'json'.");

            return 1;
        }

        try {
            $result = [];

            // List local files if requested
            if ($source === 'local' || $source === 'both') {
                $result['local'] = $this->getLocalSampleImages();
            }

            // List bucket files if requested
            if ($source === 'bucket' || $source === 'both') {
                $result['bucket'] = $this->getBucketSampleImages();
            }

            // Output the results
            if ($format === 'json') {
                $this->output->write(json_encode($result, JSON_PRETTY_PRINT));
            } else {
                $this->displayTableOutput($result);
            }

            return 0;
        } catch (\Exception $e) {
            $this->error('Failed to list sample images: '.$e->getMessage());

            return 1;
        }
    }

    /**
     * Get sample images from local storage
     *
     * @return array Information about local sample images
     */
    protected function getLocalSampleImages(): array
    {
        $this->info('Checking local sample images...');
        $sampleDisk = Storage::disk('sample_images');

        $allFiles = $sampleDisk->allFiles();

        // Filter to keep only image files
        $imageFiles = array_filter($allFiles, function ($file) {
            $extension = pathinfo($file, PATHINFO_EXTENSION);

            return in_array(strtolower($extension), ['jpg', 'jpeg', 'png', 'gif']);
        });

        // Group by show/class
        $groupedFiles = $this->groupFilesByShowClass($imageFiles);

        return [
            'total_count' => count($imageFiles),
            'shows' => $groupedFiles['shows'],
            'ungrouped' => $groupedFiles['ungrouped'],
            'storage_path' => storage_path('sample_images'),
        ];
    }

    /**
     * Get sample images from S3 bucket
     *
     * @return array Information about bucket sample images
     */
    protected function getBucketSampleImages(): array
    {
        $this->info('Checking bucket sample images...');

        // Display S3 connection info
        $this->comment('Using bucket: '.config('filesystems.disks.sample_images_bucket.bucket'));
        $this->comment('Endpoint: '.config('filesystems.disks.sample_images_bucket.endpoint'));

        $s3Disk = Storage::disk('sample_images_bucket');

        $allFiles = $s3Disk->allFiles();

        // Filter to keep only image files
        $imageFiles = array_filter($allFiles, function ($file) {
            $extension = pathinfo($file, PATHINFO_EXTENSION);

            return in_array(strtolower($extension), ['jpg', 'jpeg', 'png', 'gif']);
        });

        // Group by show/class
        $groupedFiles = $this->groupFilesByShowClass($imageFiles);

        return [
            'total_count' => count($imageFiles),
            'shows' => $groupedFiles['shows'],
            'ungrouped' => $groupedFiles['ungrouped'],
            'bucket' => config('filesystems.disks.sample_images_bucket.bucket'),
        ];
    }

    /**
     * Group files by show/class
     *
     * @param  array  $files  List of file paths
     * @return array Grouped files
     */
    protected function groupFilesByShowClass(array $files): array
    {
        $shows = [];
        $ungrouped = [];

        foreach ($files as $file) {
            // Expected pattern: show_id/class_id/image.jpg
            $pathParts = explode('/', $file);

            if (count($pathParts) >= 3) {
                $showId = $pathParts[0];
                $classId = $pathParts[1];

                if (! isset($shows[$showId])) {
                    $shows[$showId] = ['classes' => []];
                }

                if (! isset($shows[$showId]['classes'][$classId])) {
                    $shows[$showId]['classes'][$classId] = ['count' => 0, 'files' => []];
                }

                $shows[$showId]['classes'][$classId]['count']++;
                $shows[$showId]['classes'][$classId]['files'][] = basename($file);
            } else {
                $ungrouped[] = $file;
            }
        }

        return [
            'shows' => $shows,
            'ungrouped' => $ungrouped,
        ];
    }

    /**
     * Display results in table format
     *
     * @param  array  $result  The result data
     */
    protected function displayTableOutput(array $result): void
    {
        if (isset($result['local'])) {
            $this->info("\nLocal Sample Images (".$result['local']['storage_path'].'):');
            $this->info('Total images: '.$result['local']['total_count']);

            $this->displayShowsTable($result['local']['shows']);

            if (! empty($result['local']['ungrouped'])) {
                $this->warn('Ungrouped files: '.count($result['local']['ungrouped']));
                $this->listing($result['local']['ungrouped']);
            }
        }

        if (isset($result['bucket'])) {
            $this->info("\nBucket Sample Images (".$result['bucket']['bucket'].'):');
            $this->info('Total images: '.$result['bucket']['total_count']);

            $this->displayShowsTable($result['bucket']['shows']);

            if (! empty($result['bucket']['ungrouped'])) {
                $this->warn('Ungrouped files: '.count($result['bucket']['ungrouped']));
                $this->listing($result['bucket']['ungrouped']);
            }
        }
    }

    /**
     * Display shows and classes in table format
     *
     * @param  array  $shows  The shows data
     */
    protected function displayShowsTable(array $shows): void
    {
        if (empty($shows)) {
            $this->warn('No shows found');

            return;
        }

        $tableRows = [];

        foreach ($shows as $showId => $showData) {
            $isFirstShow = true;

            foreach ($showData['classes'] as $classId => $classData) {
                $tableRows[] = [
                    $isFirstShow ? $showId : '',
                    $classId,
                    $classData['count'],
                ];

                $isFirstShow = false;
            }
        }

        $this->table(
            ['Show ID', 'Class ID', 'Image Count'],
            $tableRows
        );
    }
}
