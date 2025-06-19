<?php

namespace App\Traits;

use App\Models\Photo;
use App\Models\ShowClass;
use App\Services\PathResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Trait for handling rsync operations and updating database records
 */
trait RsyncHandlerTrait
{
    /**
     * Process rsync output for proofs and update photo records
     *
     * @param  array  $output  Rsync command output
     * @param  string|null  $show_id  Show ID
     * @param  bool  $is_dry_run  Whether this was a dry run
     * @return array Processed paths
     */
    public function processProofRsyncOutput(array $output, ?string $show_id = null, bool $is_dry_run = false): array
    {
        // We only want to process output lines that relate to files that were uploaded, specifically those that
        // match our expected proof filename structures
        $allowed_filename_endings = [];
        $proofs_suffixes = config('proofgen.thumbnails');
        foreach ($proofs_suffixes as $size => $values) {
            $allowed_filename_endings[] = $values['suffix'].'.jpg';
        }
        $sync_type = 'proofs';
        $path_resolver_method = 'getProofsPath';

        return $this->processRsyncOutput($sync_type, $allowed_filename_endings, $path_resolver_method, $output, $show_id, $is_dry_run);
    }

    /**
     * Process rsync output for web images and update photo records
     *
     * @param  array  $output  Rsync command output
     * @param  string|null  $show_id  Show ID
     * @param  bool  $is_dry_run  Whether this was a dry run
     * @return array Processed paths
     */
    public function processWebImageRsyncOutput(array $output, ?string $show_id = null, bool $is_dry_run = false): array
    {
        // We only want to process output lines that relate to files that were uploaded, specifically those that
        // match our expected web image filename structures
        $allowed_filename_endings = ['_web.jpg'];
        $sync_type = 'web_images';
        $path_resolver_method = 'getWebImagesPath';

        return $this->processRsyncOutput($sync_type, $allowed_filename_endings, $path_resolver_method, $output, $show_id, $is_dry_run);
    }

    /**
     * Process rsync output for highres images and update photo records
     *
     * @param  array  $output  Rsync command output
     * @param  string|null  $show_id  Show ID
     * @param  bool  $is_dry_run  Whether this was a dry run
     * @return array Processed paths
     */
    public function processHighresImageRsyncOutput(array $output, ?string $show_id = null, bool $is_dry_run = false): array
    {
        // We only want to process output lines that relate to files that were uploaded, specifically those that
        // match our expected highres image filename structures
        $allowed_filename_endings = ['_highres.jpg'];
        $sync_type = 'highres_images';
        $path_resolver_method = 'getHighresImagesPath';

        return $this->processRsyncOutput($sync_type, $allowed_filename_endings, $path_resolver_method, $output, $show_id, $is_dry_run);
    }

    /**
     * Process rsync output and update database records
     */
    protected function processRsyncOutput(string $sync_type, array $allowed_filename_endings, string $path_resolver_method, array $output, string $show_id, bool $is_dry_run): array
    {
        Log::debug('Processing rsync output for sync type: '.$sync_type.' with show ID: '.$show_id.' and dry run: '.($is_dry_run ? 'true' : 'false'));

        $path_resolver = app(PathResolver::class);
        $uploaded_paths = [];
        $uploaded_files = [];
        $show_id = $this->getShowId($show_id);

        // Parse the rsync output to get class and file information
        foreach ($output as $line) {
            $line = trim($line);

            // We can immediately ignore these lines
            if (str_starts_with(strtolower($line), '.')
                || str_ends_with(strtolower($line), '/')
                || str_starts_with(strtolower($line), 'deleting')
            ) {
                continue;
            }

            if (! empty($line) && $this->ends_with_any($line, $allowed_filename_endings)) {
                // Extract class name and file name from the path
                $parts = explode('/', $line);

                // Handle different patterns based on context
                $class_name = null;
                $file_name = null;

                if (count($parts) >= 2) {
                    if (get_class($this) === ShowClass::class) {
                        // If we're in ShowClass and path starts with show folder, skip that part
                        if (str_starts_with(strtolower($line), strtolower($show_id))) {
                            $file_name = end($parts);
                            $class_name = $this->name;
                        }
                    } else {
                        // Standard Show context
                        $class_name = $parts[0];
                        $file_name = end($parts);
                    }

                    if ($class_name && $file_name) {
                        // Use PathResolver to build the file path based on the $sync_type we're performing here
                        $file_path = $path_resolver->$path_resolver_method($show_id, $class_name);
                        $full_path = $path_resolver->normalizePath($file_path.'/'.$file_name);
                        $uploaded_paths[] = $full_path;

                        // Keep track of file info for database updates
                        $uploaded_files[$class_name][$file_name] = [
                            'path' => $full_path,
                            'processed' => ! $is_dry_run,  // If not dry run, it was processed
                        ];
                    }
                }
            }
        }

        // Log the $uploaded_files array for debugging
        if (count($uploaded_files)) {
            Log::debug('Uploaded files', ['uploaded_files' => $uploaded_files]);
        } else {
            Log::debug('No uploaded files found in rsync output');
        }

        // Now update the database based on the rsync results
        if (! empty($uploaded_files)) {
            $this->updateDatabaseRecordsFromRsyncOutput($sync_type, $uploaded_files, $show_id, $is_dry_run);
        }

        return $uploaded_paths;
    }

    /**
     * Convenience method to update database records based on rsync output
     *
     * @param  string  $type  Type of rsync output ('proofs', 'web_images', or 'highres_images')
     * @param  array  $uploaded_files  Class/file organization of uploaded files
     * @param  string  $show_id  Show ID
     * @param  bool  $is_dry_run  Whether this was a dry run
     */
    protected function updateDatabaseRecordsFromRsyncOutput(string $type, array $uploaded_files, string $show_id, bool $is_dry_run): void
    {
        switch ($type) {
            case 'proofs':
                $this->updateDatabaseProofRecords($uploaded_files, $show_id, $is_dry_run);
                break;
            case 'web_images':
                $this->updateDatabaseWebImageRecords($uploaded_files, $show_id, $is_dry_run);
                break;
            case 'highres_images':
                $this->updateDatabaseHighresImageRecords($uploaded_files, $show_id, $is_dry_run);
                break;
            default:
                Log::warning("Unknown rsync type: {$type}");
                break;
        }
    }

    /**
     * Update database records for proof uploads
     *
     * @param  array  $uploaded_files  Class/file organization of proof uploads
     * @param  string  $show_id  Show ID
     * @param  bool  $is_dry_run  Whether this was a dry run
     */
    protected function updateDatabaseProofRecords(array $uploaded_files, string $show_id, bool $is_dry_run): void
    {
        // Get all thumbnail suffixes from config
        $thumbnail_sizes = config('proofgen.thumbnails');
        $thumbnail_sizes = array_map(function ($item) {
            return $item['suffix'];
        }, $thumbnail_sizes);

        // Process each class
        foreach ($uploaded_files as $class_name => $files) {
            // Get the ShowClass model
            $show_class = ShowClass::where('id', $show_id.'_'.$class_name)->first();
            if (! $show_class) {
                Log::warning("Show class not found for ID: {$show_id}_{$class_name}");

                continue;
            }

            // Group files by proof number
            $proof_numbers = [];
            foreach ($files as $file_name => $info) {
                $proof_number = pathinfo($file_name, PATHINFO_FILENAME);

                // Remove thumbnail suffixes to get the base proof number
                foreach ($thumbnail_sizes as $suffix) {
                    $proof_number = str_replace($suffix, '', $proof_number);
                }

                $proof_numbers[$proof_number][] = [
                    'file_name' => $file_name,
                    'processed' => $info['processed'],
                ];
            }

            // Now update Photo records
            foreach ($proof_numbers as $proof_number => $files_info) {
                // If we're on a dry run, and we get a proof number with any number of files included here that means
                // that it's missing at least one thumbnail, when it's marked as having its thumbnails uploaded,
                // so we should reset that flag because they're not all uploaded
                if ($is_dry_run) {
                    $photo = $show_class->photos()->where('proof_number', $proof_number)->whereNotNull('proofs_uploaded_at')->first();
                    if ($photo) {
                        Log::debug("Pending upload found for proof: {$proof_number}");
                        $photo->proofs_uploaded_at = null;
                        $photo->save();
                    }
                }
                // If this is not a dry run and the number of thumbnails uploaded matches the number of
                // thumbnails we expect, then we can mark this proof as uploaded
                elseif (count($files_info) === count($thumbnail_sizes)) {
                    $photo = $show_class->photos()->where('proof_number', $proof_number)->first();
                    if ($photo) {
                        Log::debug("Marking uploaded for proof: {$proof_number}");
                        $photo->proofs_uploaded_at = Carbon::now();
                        $photo->save();
                    }
                }
            }

            // Now we'll look for any Photo records that are marked as having their proofs generated, but aren't
            // showing that their proofs have been uploaded yet - meaning they weren't included in this recent rsync
            // output either - which can only indicate that either they were previously uploaded, or they don't
            // actually exist in our local proofs directory
            $not_in_list = $show_class->photos()
                ->whereNotNull('proofs_generated_at')
                ->whereNull('proofs_uploaded_at')
                ->get();

            foreach ($not_in_list as $photo) {
                // Skip if this photo was in our upload list
                if (isset($proof_numbers[$photo->proof_number])) {
                    continue;
                }

                // Confirm that there are actually local proofs for this photo
                $proofs_exist = $photo->checkPathForProofs();
                if (! $proofs_exist) {
                    // If the proofs don't exist, we need to reset the proofs_generated_at timestamp
                    $photo->proofs_generated_at = null;
                    $photo->save();

                    Log::debug('Local proofs not found for proof: '.$photo->proof_number.' during updateDatabaseProofRecords()');

                    continue;
                }

                // If rsync didn't upload them, and we show them being present on the local filesystem we can assume
                // that they're uploaded
                $photo->proofs_uploaded_at = Carbon::now();
                $photo->save();
            }
        }
    }

    /**
     * Update database records for web image uploads
     *
     * @param  array  $web_image_timestamps  Class/file organization of web image uploads
     * @param  string  $show_id  Show ID
     * @param  bool  $is_dry_run  Whether this was a dry run
     */
    protected function updateDatabaseWebImageRecords(array $web_image_timestamps, string $show_id, bool $is_dry_run): void
    {
        // Process each class
        foreach ($web_image_timestamps as $class_name => $files) {
            // Get the ShowClass model
            $show_class = ShowClass::where('id', $show_id.'_'.$class_name)->first();
            if (! $show_class) {
                Log::warning("Show class not found for ID: {$show_id}_{$class_name}");

                continue;
            }

            // Group files by proof number
            $proof_numbers = [];
            foreach ($files as $file_name => $info) {
                $proof_number = pathinfo($file_name, PATHINFO_FILENAME);
                $proof_number = str_replace('_web', '', $proof_number);

                $proof_numbers[$proof_number][] = [
                    'file_name' => $file_name,
                    'processed' => $info['processed'],
                ];
            }

            // Now update Photo records
            foreach ($proof_numbers as $proof_number => $files_info) {
                // If we're on a dry run and we find a web image in the output, that means
                // it's not yet uploaded, so we should reset the web_image_uploaded_at flag
                if ($is_dry_run) {
                    $photo = $show_class->photos()->where('proof_number', $proof_number)->whereNotNull('web_image_uploaded_at')->first();
                    if ($photo) {
                        Log::debug("Pending web image upload found for proof: {$proof_number}");
                        $photo->web_image_uploaded_at = null;
                        $photo->save();
                    }
                }
                // If this is not a dry run, we mark the web image as uploaded
                else {
                    $photo = $show_class->photos()->where('proof_number', $proof_number)->first();
                    if ($photo) {
                        Log::debug("Marking web image uploaded for proof: {$proof_number}");
                        $photo->web_image_uploaded_at = Carbon::now();
                        $photo->save();
                    }
                }
            }

            // Now we'll look for any Photo records that are marked as having their web images generated, but aren't
            // showing that their web images have been uploaded yet - meaning they weren't included in this recent rsync
            // output either - which can only indicate that either they were previously uploaded, or they don't
            // actually exist in our local web images directory
            $not_in_list = $show_class->photos()
                ->whereNotNull('web_image_generated_at')
                ->whereNull('web_image_uploaded_at')
                ->get();

            foreach ($not_in_list as $photo) {
                // Skip if this photo was in our upload list
                if (isset($proof_numbers[$photo->proof_number])) {
                    continue;
                }

                // Confirm that there is actually a local web image for this photo
                $web_image_exists = $photo->checkPathForWebImage();
                if (! $web_image_exists) {
                    // If the web image doesn't exist, we need to reset the web_image_generated_at timestamp
                    $photo->web_image_generated_at = null;
                    $photo->save();

                    Log::debug('Local web image not found for proof: '.$photo->proof_number.' during updateDatabaseWebImageRecords()');

                    continue;
                }

                // If rsync didn't upload it, and we show it being present on the local filesystem, we can assume
                // that it's already uploaded
                $photo->web_image_uploaded_at = Carbon::now();
                $photo->save();
            }
        }
    }

    /**
     * Update database records for highres image uploads
     *
     * @param  array  $highres_image_timestamps  Class/file organization of highres image uploads
     * @param  string  $show_id  Show ID
     * @param  bool  $is_dry_run  Whether this was a dry run
     */
    protected function updateDatabaseHighresImageRecords(array $highres_image_timestamps, string $show_id, bool $is_dry_run): void
    {
        // Process each class
        foreach ($highres_image_timestamps as $class_name => $files) {
            // Get the ShowClass model
            $show_class = ShowClass::where('id', $show_id.'_'.$class_name)->first();
            if (! $show_class) {
                Log::warning("Show class not found for ID: {$show_id}_{$class_name}");

                continue;
            }

            // Group files by proof number
            $proof_numbers = [];
            foreach ($files as $file_name => $info) {
                $proof_number = pathinfo($file_name, PATHINFO_FILENAME);
                $proof_number = str_replace('_highres', '', $proof_number);

                $proof_numbers[$proof_number][] = [
                    'file_name' => $file_name,
                    'processed' => $info['processed'],
                ];
            }

            // Now update Photo records
            foreach ($proof_numbers as $proof_number => $files_info) {
                // If we're on a dry run and we find a highres image in the output, that means
                // it's not yet uploaded, so we should reset the highres_image_uploaded_at flag
                if ($is_dry_run) {
                    $photo = $show_class->photos()->where('proof_number', $proof_number)->whereNotNull('highres_image_uploaded_at')->first();
                    if ($photo) {
                        Log::debug("Pending highres image upload found for proof: {$proof_number}");
                        $photo->highres_image_uploaded_at = null;
                        $photo->save();
                    }
                }
                // If this is not a dry run, we mark the highres image as uploaded
                else {
                    $photo = $show_class->photos()->where('proof_number', $proof_number)->first();
                    if ($photo) {
                        Log::debug("Marking highres image uploaded for proof: {$proof_number}");
                        $photo->highres_image_uploaded_at = Carbon::now();
                        $photo->save();
                    }
                }
            }

            // Now we'll look for any Photo records that are marked as having their highres images generated, but aren't
            // showing that their highres images have been uploaded yet - meaning they weren't included in this recent rsync
            // output either - which can only indicate that either they were previously uploaded, or they don't
            // actually exist in our local highres images directory
            $not_in_list = $show_class->photos()
                ->whereNotNull('highres_image_generated_at')
                ->whereNull('highres_image_uploaded_at')
                ->get();

            foreach ($not_in_list as $photo) {
                // Skip if this photo was in our upload list
                if (isset($proof_numbers[$photo->proof_number])) {
                    continue;
                }

                // Confirm that there is actually a local highres image for this photo
                $highres_image_exists = $photo->checkPathForHighresImage();
                if (! $highres_image_exists) {
                    // If the highres image doesn't exist, we need to reset the highres_image_generated_at timestamp
                    $photo->highres_image_generated_at = null;
                    $photo->save();

                    Log::debug('Local highres image not found for proof: '.$photo->proof_number.' during updateDatabaseHighresImageRecords()');

                    continue;
                }

                // If rsync didn't upload it, and we show it being present on the local filesystem, we can assume
                // that it's already uploaded
                $photo->highres_image_uploaded_at = Carbon::now();
                $photo->save();
            }
        }
    }

    public function getShowId(?string $show_id): string
    {
        // Determine the show_id based on context
        if ($show_id === null) {
            if (isset($this->id) && is_string($this->id)) {
                if (str_contains($this->id, '_')) {
                    // We're in a ShowClass
                    $parts = explode('_', $this->id);
                    $show_id = $parts[0];
                } else {
                    // We're in a Show
                    $show_id = $this->id;
                }
            } elseif (isset($this->show_id)) {
                $show_id = $this->show_id;
            }
        }

        return $show_id;
    }

    public function ends_with_any($string, array $endings): bool
    {
        $string = strtolower($string);
        foreach ($endings as $ending) {
            if (str_ends_with($string, strtolower($ending))) {
                return true;
            }
        }

        return false;
    }
}
