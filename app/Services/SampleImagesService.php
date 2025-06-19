<?php

namespace App\Services;

use App\Exceptions\SampleImagesNotFoundException;
use Exception;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SampleImagesService
{
    /**
     * Check if sample images are available
     *
     * @return bool True if any sample images exist
     */
    public function hasSampleImages(): bool
    {
        $sampleDisk = Storage::disk('sample_images');

        // Check if we have any image files (recursively)
        $allFiles = $sampleDisk->allFiles();

        // Filter to keep only image files
        $imageFiles = array_filter($allFiles, function ($file) {
            $extension = pathinfo($file, PATHINFO_EXTENSION);

            return in_array(strtolower($extension), ['jpg', 'jpeg', 'png', 'gif']);
        });

        return count($imageFiles) > 0;
    }

    /**
     * Download sample images from S3 bucket
     *
     * @return bool Success status
     *
     * @throws Exception If download fails
     */
    public function downloadSampleImages(): bool
    {
        // Get the S3 disk for sample images
        $s3Disk = Storage::disk('sample_images_bucket');
        $localDisk = Storage::disk('sample_images');

        try {
            // List all files in the S3 bucket
            $files = $s3Disk->allFiles();

            if (empty($files)) {
                throw new Exception('No files found in the sample images bucket');
            }

            // Keep track of downloaded files for logging
            $downloadCount = 0;

            // Download each file
            foreach ($files as $file) {
                // Create the directory if it doesn't exist
                $directory = dirname($file);
                if (! empty($directory) && $directory !== '.') {
                    $localDisk->makeDirectory($directory);
                }

                // Copy the file from S3 to local
                $contents = $s3Disk->get($file);
                $localDisk->put($file, $contents);
                $downloadCount++;
            }

            Log::info("Successfully downloaded $downloadCount sample images");

            return true;
        } catch (Exception $e) {
            Log::error('Failed to download sample images', [
                'error' => $e->getMessage(),
            ]);

            throw new Exception("Failed to download sample images: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Upload sample images to S3 bucket from local directory
     *
     * @param  string|null  $sourcePath  Optional custom source path (defaults to sample_images disk)
     * @param  bool  $overwrite  Whether to overwrite existing files in the bucket
     * @return array Statistics about the upload operation
     *
     * @throws Exception If upload fails
     */
    public function uploadSampleImages(?string $sourcePath = null, bool $overwrite = true): array
    {
        // Get the S3 disk for sample images
        $s3Disk = Storage::disk('sample_images_bucket');

        // Source can be either a custom path or the sample_images disk
        $useLocalDisk = ($sourcePath === null);
        $localDisk = $useLocalDisk ? Storage::disk('sample_images') : null;

        // Ensure source path exists if provided
        if (! $useLocalDisk && ! File::exists($sourcePath)) {
            throw new Exception("Source directory not found: $sourcePath");
        }

        // Stats to return
        $stats = [
            'uploaded' => 0,
            'skipped' => 0,
            'failed' => 0,
            'total' => 0,
        ];

        try {
            // Get all files to upload
            $files = $useLocalDisk ? $localDisk->allFiles() : $this->getAllFiles($sourcePath);
            // Filter to keep only image files
            $files = $this->filterImageFiles($files);
            $stats['total'] = count($files);

            if (empty($files)) {
                throw new Exception('No files found to upload');
            }

            // Get existing files if we're not overwriting
            $existingFiles = $overwrite ? [] : $s3Disk->allFiles();

            // Upload each file
            foreach ($files as $file) {
                // Get relative path for storing in S3
                $relativePath = $useLocalDisk ? $file : $this->getRelativePath($sourcePath, $file);

                // Skip if file exists and we're not overwriting
                if (! $overwrite && in_array($relativePath, $existingFiles)) {
                    $stats['skipped']++;

                    continue;
                }

                try {
                    // Get file contents
                    $contents = $useLocalDisk ? $localDisk->get($file) : File::get($file);

                    // Upload to S3
                    $s3Disk->put($relativePath, $contents);
                    $stats['uploaded']++;
                } catch (Exception $e) {
                    Log::error("Failed to upload file: $file", [
                        'error' => $e->getMessage(),
                    ]);
                    $stats['failed']++;
                }
            }

            Log::info('Sample images upload completed', $stats);

            return $stats;
        } catch (Exception $e) {
            Log::error('Failed to upload sample images', [
                'error' => $e->getMessage(),
                'stats' => $stats,
            ]);

            throw new Exception("Failed to upload sample images: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Get all files recursively from a directory
     *
     * @param  string  $directory  The directory to scan
     * @return array An array of file paths
     */
    protected function getAllFiles(string $directory): array
    {
        $files = [];

        foreach (File::allFiles($directory) as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Filter array of files returned from filesystem to only include image files
     *
     * @param  array  $files  An array of file paths
     * @return array An array of image file paths
     */
    protected function filterImageFiles(array $files): array
    {
        return array_filter($files, function ($file) {
            $extension = pathinfo($file, PATHINFO_EXTENSION);

            return in_array(strtolower($extension), ['jpg', 'jpeg', 'png', 'gif']);
        });
    }

    /**
     * Get the relative path for a file from a base directory
     *
     * @param  string  $basePath  The base directory
     * @param  string  $filePath  The full file path
     * @return string The relative path
     */
    protected function getRelativePath(string $basePath, string $filePath): string
    {
        $basePath = rtrim($basePath, '/').'/';

        if (strpos($filePath, $basePath) === 0) {
            return substr($filePath, strlen($basePath));
        }

        return $filePath;
    }

    /**
     * Ensure sample images are available, downloading them if needed
     *
     * @return bool Success status
     *
     * @throws Exception If download fails
     */
    public function ensureSampleImagesAvailable(): bool
    {
        try {
            if (! $this->hasSampleImages()) {
                // If we're missing sample images, try to download them
                if (config('proofgen.auto_download_sample_images', false)) {
                    return $this->downloadSampleImages();
                } else {
                    throw new SampleImagesNotFoundException("Sample images not found. Run 'php artisan proofgen:download-samples' to download them.");
                }
            }

            return true;
        } catch (SampleImagesNotFoundException $e) {
            // Re-throw SampleImagesNotFoundException to be caught by controllers/tests
            throw $e;
        } catch (Exception $e) {
            // Log and wrap other exceptions
            Log::error('Error checking or downloading sample images', [
                'error' => $e->getMessage(),
            ]);

            throw new Exception("Failed to ensure sample images are available: {$e->getMessage()}", 0, $e);
        }
    }
}
