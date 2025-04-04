<?php

namespace App\Models;

use App\Jobs\Photo\GenerateThumbnails;
use App\Jobs\Photo\GenerateWebImage;
use App\Proofgen\Utility;
use App\Services\PathResolver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;

class ShowClass extends Model
{
    protected $table = 'show_classes';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $guarded = [
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'id' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Model events
    protected static function boot()
    {
        parent::boot();

        static::created(function (ShowClass $model) {
            // Check the ShowClass directory for a directory named 'originals'
            // if it's there, and it has images in it, we'll import them
            // into the database
            $existing_imported_photos = $model->importExistingPhotosFromOriginalsDirectory();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function getFullPathAttribute(): string
    {
        return config('proofgen.fullsize_home_dir') . '/' . $this->relative_path;
    }

    public function getRelativePathAttribute(): string
    {
        return str_replace('_', '/', $this->id);
    }

    public function getOriginalsPathAttribute()
    {
        return $this->relative_path . '/originals';
    }

    public function getFullOriginalsPathAttribute()
    {
        return config('proofgen.fullsize_home_dir') . '/' . $this->originals_path;
    }

    public function getRemoteWebImagesPathAttribute()
    {
        $path_resolver = app(PathResolver::class);
        $remote_web_images_path = $path_resolver->getRemoteWebImagesPath($this->show->name, $this->name);
        return $path_resolver->normalizePath($remote_web_images_path);
    }

    public function getWebImagesPathAttribute(): string
    {
        $path_resolver = app(PathResolver::class);
        $web_images_path = $path_resolver->getWebImagesPath($this->show->name, $this->name);
        return $path_resolver->normalizePath($web_images_path);
    }

    public function getFullWebImagesPathAttribute()
    {
        $path_resolver = app(PathResolver::class);
        $web_images_path = $this->web_images_path;
        return $path_resolver->getAbsolutePath($web_images_path, config('proofgen.fullsize_home_dir'));
    }

    public function getProofsPathAttribute()
    {
        $path_resolver = app(PathResolver::class);
        $proofs_path = $path_resolver->getProofsPath($this->show->name, $this->name);
        return $path_resolver->normalizePath($proofs_path);
    }

    public function getFullProofsPathAttribute()
    {
        $proofs_path = $this->proofs_path;
        $path_resolver = app(PathResolver::class);
        return $path_resolver->getAbsolutePath($proofs_path, config('proofgen.fullsize_home_dir'));
    }

    public function getRemoteProofsPathAttribute()
    {
        $path_resolver = app(PathResolver::class);
        $remote_proofs_path = $path_resolver->getRemoteProofsPath($this->show->name, $this->name);
        return $path_resolver->normalizePath($remote_proofs_path);
    }

    public function show(): BelongsTo
    {
        return $this->belongsTo(Show::class, 'show_id', 'id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class, 'show_class_id', 'id');
    }

    public function photosProofed(): HasMany
    {
        return $this->photos()->whereNotNull('proofs_generated_at');
    }

    public function photosNotProofed(): HasMany
    {
        return $this->photos()->whereNull('proofs_generated_at');
    }

    public function photosProofsUploaded(): HasMany
    {
        return $this->photos()->whereNotNull('proofs_uploaded_at');
    }

    public function photosProofedNotUploaded(): HasMany
    {
        return $this->photos()->whereNotNull('proofs_generated_at')->whereNull('proofs_uploaded_at');
    }

    public function photosWebImaged(): HasMany
    {
        return $this->photos()->whereNotNull('web_image_generated_at');
    }

    public function photosNotWebImaged(): HasMany
    {
        return $this->photos()->whereNull('web_image_generated_at');
    }

    public function photosWebImagesUploaded(): HasMany
    {
        return $this->photos()->whereNotNull('web_image_uploaded_at');
    }

    public function photosWebImagedNotUploaded(): HasMany
    {
        return $this->photos()->whereNotNull('web_image_generated_at')->whereNull('web_image_uploaded_at');
    }

    public function processingCounts(): array
    {
        $return['photos_imported'] = $this->photos()->get();

        $return['photos_proofed'] = $this->photosProofed()->get();
        $return['photos_pending_proofs'] = $this->photosNotProofed()->get();
        $return['photos_proofs_uploaded'] = $this->photosProofsUploaded()->get();
        $return['photos_pending_proof_uploads'] = $this->photosProofedNotUploaded()->get();

        $return['photos_web_images_generated'] = $this->photosWebImaged()->get();
        $return['photos_pending_web_images'] = $this->photosNotWebImaged()->get();
        $return['photos_web_images_uploaded'] = $this->photosWebImagesUploaded()->get();
        $return['photos_pending_web_image_uploads'] = $this->photosWebImagedNotUploaded()->get();

        return $return;
    }

    public function getImagesPendingImport(): array
    {
        $contents = Utility::getContentsOfPath($this->relative_path, false);

        $images = [];
        if(isset($contents['images']))
            $images = $contents['images'];

        return $images;
    }

    public function importPendingImages(): array
    {
        $images = $this->getImagesPendingImport();
        $imported = [];
        foreach($images as $image) {
            /** @var FileAttributes $image */
            $file_path = $image->path();
            $photo = $this->importPhotoFromPath($file_path);
            if(isset($photo->is_new) && $photo->is_new === true) {
                $imported[] = $photo;
            }
        }

        return $imported;
    }

    /**
     * Parses the file path to extract the proof number and file type to determine if we have
     * this photo in the database already. If not, it creates a new Photo record. (Which, the Photo model starts a
     * string of methods to generate the sha1 hash, metadata, and check for proofs and web images)
     *
     * @param string $file_path
     * @return Photo
     */
    public function importPhotoFromPath(string $file_path): Photo
    {
        $proof_number = $this->proofNumberFromPath($file_path);
        $photo_id = $this->photoIdFromProofNumber($proof_number);
        $file_type = pathinfo($file_path, PATHINFO_EXTENSION);
        $file_type = strtolower($file_type);

        // Check if we have this database record
        $photo = $this->photos()->where('id', $photo_id)->first();
        if( ! $photo) {
            // If the photo doesn't exist, create it
            // Open the file to generate it's sha1 and pass to PhotoMetadata
            // to generate the metadata
            $photo = new Photo();
            $photo->show_class_id = $this->id;
            $photo->proof_number = $proof_number;
            $photo->file_type = $file_type;
            $photo->save();
            $photo->is_new = true;
        }

        return $photo;
    }

    public function proofNumberFromPath(string $file_path): string
    {
        $proof_number = pathinfo($file_path, PATHINFO_FILENAME);
        $proof_number = explode('.', $proof_number);
        $proof_number = array_shift($proof_number);

        // There are cases where this file path passed to this method would be the path to a web image or proof, which
        // will have a '_'.$suffix indicating the size of the image. We'll want to handle these cases by attempting a
        // str_replace with the various suffixes
        $thumbnail_sizes = config('proofgen.thumbnails');
        $thumbnail_sizes = array_map(function($item) {
            return $item['suffix'];
        }, $thumbnail_sizes);
        $thumbnail_sizes[] = '_web';

        foreach($thumbnail_sizes as $thumbnail_size) {
            $proof_number = str_replace($thumbnail_size, '', $proof_number);
        }

        return $proof_number;
    }

    public function photoIdFromProofNumber(string $proof_number): string
    {
        return $this->id.'_'.$proof_number;
    }

    public function importExistingPhotosFromOriginalsDirectory(): int
    {
        $pathResolver = $this->pathResolver ?? app(PathResolver::class);
        $show_class = new \App\Proofgen\ShowClass($this->show->name, $this->name, $pathResolver);
        $originals_images = $show_class->getImportedImages();

        $new_records = 0;
        foreach($originals_images as $image) {
            /** @var FileAttributes $image */
            $file_path = $image->path();
            $photo = $this->importPhotoFromPath($file_path);
            if(isset($photo->is_new) && $photo->is_new === true) {
                $new_records++;
            }
        }

        return $new_records;
    }

    public function proofPendingPhotos(): int
    {
        $photos = $this->photosNotProofed()->get();

        return $this->queueThumbnailGeneration($photos);
    }

    /**
     * Queue thumbnails for an array of Photos
     *
     * @param array|Collection $photos
     * @return int
     */
    public function queueThumbnailGeneration(array | Collection $photos): int
    {
        $queued = 0;
        foreach($photos as $photo) {
            GenerateThumbnails::dispatch($photo->id, $this->proofs_path)->onQueue('thumbnails');
            $queued++;
        }

        return $queued;
    }

    /**
     * Queue web images for an array of Photos
     *
     * @param array|Collection $photos
     * @return int
     */
    public function queueWebImageGeneration(array|Collection $photos): int
    {
        $queued = 0;
        foreach($photos as $photo) {
            GenerateWebImage::dispatch($photo->id, $this->web_images_path)->onQueue('thumbnails');
            $queued++;
        }
        return $queued;
    }

    /**
     * Queue all imported Photos for thumbnail generation
     *
     * @return int
     */
    public function regenerateProofs(): int
    {
        // Remove the local proof files for this class and reset the proofs_generated_at
        foreach($this->photosProofed()->get() as $photo) {
            /** @var Photo $photo */
            $photo->deleteLocalProofs();
        }

        return $this->queueThumbnailGeneration($this->photos()->get());
    }

    public function webImagePendingPhotos(): int
    {
        return $this->queueWebImageGeneration($this->photosNotWebImaged()->get());
    }

    public function rsyncWebImagesCommand($dry_run = false): string
    {
        $path_resolver = app(PathResolver::class);
        $local_full_path = $path_resolver->getAbsolutePath($this->web_images_path, config('proofgen.fullsize_home_dir') . '/').'/';
        $dry_run = $dry_run === true ? '--dry-run' : '';

        return 'rsync -avz --delete '.$dry_run.' -e "ssh -i '.config('proofgen.sftp.private_key').'" '.
            $local_full_path.' forge@'.config('proofgen.sftp.host').':'.config('proofgen.sftp.web_images_path').
            '/'.$this->remote_web_images_path;
    }

    public function rsyncProofsCommand($dry_run = false): string
    {
        $path_resolver = app(PathResolver::class);
        $local_full_path = $path_resolver->getAbsolutePath($this->proofs_path, config('proofgen.fullsize_home_dir') . '/') . '/';
        $dry_run = $dry_run === true ? '--dry-run' : '';

        return 'rsync -avz --delete '.$dry_run.' -e "ssh -i '.config('proofgen.sftp.private_key').'" '.
            $local_full_path.' forge@'.config('proofgen.sftp.host').':'.config('proofgen.sftp.path').
            '/'.$this->remote_proofs_path;
    }

    /**
     * Get the local proof images from the fullsize disk
     *
     * @return FileAttributes[]
     */
    public function localProofFiles(): array /* \League\Flysystem\FileAttributes[] */
    {
        if( ! Storage::disk('fullsize')->exists($this->proofs_path)) {
            Storage::disk('fullsize')->makeDirectory($this->proofs_path);
        }

        $photos = Utility::getContentsOfPath($this->proofs_path);
        $photos = $photos['images'] ?? [];

        // Get the proof suffixes from the config
        $thumbnail_sizes = config('proofgen.thumbnails');
        $thumbnail_sizes = array_map(function($item) {
            return $item['suffix'];
        }, $thumbnail_sizes);

        // Loop through $photos to determine if one of these suffixes is in the filename
        // If it is, we'll add it to the $proofs array
        $proofs = [];
        foreach($photos as $photo) {
            /** @var FileAttributes $photo */
            // Check if the proof number is in the filename
            foreach($thumbnail_sizes as $thumbnail_size) {
                if(str_contains($photo->path(), $thumbnail_size)) {
                    $proofs[] = $photo;
                    break;
                }
            }
        }

        return $proofs;
    }

    /**
     * Operation to sync the local proof files with the database
     *
     * @return true
     */
    public function localProofsSync(): true
    {
        $proofs = $this->localProofFiles();

        // Log::debug('localProofsSync found '.count($proofs).' proofs on filesystem');

        // Get the suffixes for the thumbnail files
        $thumbnail_sizes = config('proofgen.thumbnails');
        $thumbnail_sizes = array_map(function($item) {
            return $item['suffix'];
        }, $thumbnail_sizes);

        $existing_proofed_proof_numbers = [];
        foreach($proofs as $photo) {
            $file_path = $photo->path();
            $last_modified = $photo->lastModified();
            $proof_number = pathinfo($file_path, PATHINFO_FILENAME);
            $proof_number = explode('.', $proof_number);
            $proof_number = array_shift($proof_number);

            // Remove the suffix from the proof number
            foreach($thumbnail_sizes as $thumbnail_size) {
                $proof_number = str_replace($thumbnail_size, '', $proof_number);
            }

            $existing_proofed_proof_numbers[$proof_number][] = $last_modified;
        }

        $suffix_count = count($thumbnail_sizes);
        foreach($existing_proofed_proof_numbers as $proof_number => $last_modified_array) {

            // First, ensure that we have the same number of $last_modified_array values as there are $suffixes
            // If not, there's missing proofs and we'll want to un-set the proofs_generated_at on the corresponding
            // Photo record (if it exists)
            if(count($last_modified_array) !== $suffix_count) {
                $photo_record = $this->photos()->where('id', $this->id.'_'.$proof_number)->first();
                if($photo_record) {
                    $photo_record->proofs_generated_at = null;
                    $photo_record->save();
                }

                continue;
            }

            // Check if we have this database record
            $photo_record = $this->photos()->where('id', $this->id.'_'.$proof_number)->first();
            if($photo_record) {

                // Get the higher of the last modified times
                $last_modified = max($last_modified_array);

                if($last_modified !== $photo_record->proofs_generated_at) {
                    // If the last modified date is different, update it
                    $photo_record->proofs_generated_at = Carbon::createFromTimestamp($last_modified);
                    $photo_record->save();
                }
            }
        }

        // Now we need to check somehow which Photos that are marked as having proofs_generated_at but didn't appear
        // here - and un-mark them
        $array_of_proof_numbers = array_keys($existing_proofed_proof_numbers);
        $photos_not_included = $this->photos()->whereNotNull('proofs_generated_at')->whereNotIn('proof_number', $array_of_proof_numbers)->get();
        if($photos_not_included->count()) {
            foreach($photos_not_included as $photo) {
                Log::debug('Reverting proof generation/upload state for photo: '.$photo->id);
                $photo->proofs_generated_at = null;
                // We're specifically not resetting the proofs_uploaded_at here because they _might_ be uploaded,
                // but just not locally existing on this filesystem
                $photo->save();
            }
        }

        return true;
    }

    /**
     * Get the local web images from the fullsize disk
     *
     * @return FileAttributes[]
     */
    public function localWebImageFiles(): array /* \League\Flysystem\FileAttributes[] */
    {
        if( ! Storage::disk('fullsize')->exists($this->web_images_path)) {
            Storage::disk('fullsize')->makeDirectory($this->web_images_path);
        }

        $photos = Utility::getContentsOfPath($this->web_images_path);
        $photos = $photos['images'] ?? [];

        $web_images = [];
        foreach($photos as $photo) {
            // If there is a '_web' in the filename, we'll add it to the web_images array
            /** @var FileAttributes $photo */
            if(str_contains($photo->path(), '_web')) {
                $web_images[] = $photo;
            }
        }

        return $web_images;
    }

    /**
     * Operation to sync the local web image files with the database
     *
     * @return true
     */
    public function localWebImageSync(): true
    {
        $web_images = $this->localWebImageFiles();

        // Log::debug('localWebImageSync found '.count($web_images).' web images on filesystem');

        $proof_numbers_included_in_web_images = [];
        foreach($web_images as $photo) {
            $file_path = $photo->path();
            $last_modified = $photo->lastModified();
            $proof_number = pathinfo($file_path, PATHINFO_FILENAME);
            $proof_number = explode('.', $proof_number);
            $proof_number = array_shift($proof_number);
            $proof_number = str_replace('_web', '', $proof_number);

            $proof_numbers_included_in_web_images[] = $proof_number;

            // Check if we have this database record
            $photo_record = $this->photos()->where('id', $this->id.'_'.$proof_number)->first();
            if($photo_record) {
                if($last_modified !== $photo_record->web_image_generated_at) {
                    // If the last modified date is different, update it
                    $photo_record->web_image_generated_at = Carbon::createFromTimestamp($last_modified);
                    $photo_record->save();
                }
            }
        }

        // Let's ensure that we don't have any Photo records that indicate their web images are generated but didn't
        // exist in the filesystem
        $photos_not_included = $this->photos()->whereNotNull('web_image_generated_at')->whereNotIn('proof_number', $proof_numbers_included_in_web_images)->get();
        if($photos_not_included->count()) {
            foreach($photos_not_included as $photo) {
                Log::debug('Reverting web image generation/upload state for photo: '.$photo->id);
                $photo->web_image_generated_at = null;
                // We're specifically not resetting the web_image_uploaded_at here because they _might_ be uploaded,
                // but just not locally existing on this filesystem
                $photo->save();
            }
        }

        return true;
    }

    public function webImageUploads(): array
    {
        $remote_web_images_path = '/'.$this->remote_web_images_path;
        // Log::debug('Remote web images path: '.$remote_web_images_path);
        if( ! Storage::disk('remote_web_images')->exists($remote_web_images_path)){
            Storage::disk('remote_web_images')->makeDirectory($remote_web_images_path);
        }

        $path_resolver = app(PathResolver::class);
        $command = $this->rsyncWebImagesCommand();
        exec($command, $output, $returnCode);

        $uploaded_web_images = [];
        foreach ($output as $line) {
            $line = trim($line);

            if (!empty($line) && str_starts_with(strtolower($line), strtolower($this->show_folder))) {
                $parts = explode('/', $line);
                $fileName = end($parts);

                if (!empty($fileName) && str_ends_with($fileName, '.jpg')) {
                    $uploaded_web_images[] = $path_resolver->normalizePath($this->web_images_path.'/'.$fileName);
                }
            }
        }

        // Anything that is in the $uploaded_web_images array should have its web_image_uploaded_at set
        // to now()
        foreach($uploaded_web_images as $uploaded_web_image) {
            Log::debug('Uploaded web image: '.$uploaded_web_image);
            $proof_number = pathinfo($uploaded_web_image, PATHINFO_FILENAME);
            $file_extension = pathinfo($uploaded_web_image, PATHINFO_EXTENSION);
            $proof_number = str_replace('_web', '', $proof_number);
            $proof_number = str_replace($file_extension, '', $proof_number);
            $photo = $this->photos()->where('proof_number', $proof_number)->first();
            if($photo) {
                $photo->web_image_uploaded_at = now();
                $photo->save();
            }
        }

        return $uploaded_web_images;
    }

    /**
     * Perform a dry run of the rsync command to determine what files need to be uploaded
     *
     * @return array
     */
    public function pendingWebImageUploads(): array
    {
        $remote_web_images_path = '/'.$this->remote_web_images_path;
        // Log::debug('Remote web images path: '.$remote_web_images_path);
        if( ! Storage::disk('remote_web_images')->exists($remote_web_images_path)){
            Storage::disk('remote_web_images')->makeDirectory($remote_web_images_path);
        }

        $path_resolver = app(PathResolver::class);
        // Run a dry run of the rsync to determine what files need to be uploaded
        $command = $this->rsyncWebImagesCommand(true);
        exec($command, $output, $returnCode);

        $pending_web_images = [];
        foreach ($output as $line) {
            $line = trim($line);

            if (!empty($line) && str_starts_with(strtolower($line), strtolower($this->show_folder))) {
                $parts = explode('/', $line);
                $fileName = end($parts);

                if (!empty($fileName) && str_ends_with($fileName, '.jpg')) {
                    $pending_web_images[] = $path_resolver->normalizePath($this->web_images_path.'/'.$fileName);
                }
            }
        }

        // Get Photo records that have web_image_generated_at set but not web_image_uploaded_at
        $photos = $this->photos()->whereNotNull('web_image_generated_at')->whereNull('web_image_uploaded_at')->get();
        // Anything that is in this collection but not in the $pending_web_images array is already uploaded and we'll
        // update the web_image_uploaded_at field
        foreach($photos as $photo) {
            $web_image_path = $this->web_images_path.'/'.$photo->proof_number.'_web.'.$photo->file_type;
            if( ! in_array($web_image_path, $pending_web_images)) {
                $photo->web_image_uploaded_at = now();
                $photo->save();
            }
        }

        return $pending_web_images;
    }

    public function proofUploads()
    {
        $remote_proofs_path = '/'.$this->remote_proofs_path;
        if( ! Storage::disk('remote_proofs')->exists($remote_proofs_path)){
            Storage::disk('remote_proofs')->makeDirectory($remote_proofs_path);
        }

        $path_resolver = app(PathResolver::class);
        $command = $this->rsyncProofsCommand();
        exec($command, $output, $returnCode);

        $uploaded_proofs = [];
        foreach ($output as $line) {
            $line = trim($line);

            if (!empty($line) && str_starts_with(strtolower($line), strtolower($this->show_folder))) {
                $parts = explode('/', $line);
                $fileName = end($parts);

                if (!empty($fileName) && str_ends_with($fileName, '.jpg')) {
                    $uploaded_proofs[] = $path_resolver->normalizePath($this->proofs_path.'/'.$fileName);
                }
            }
        }

        Log::debug('Uploaded proofs: '.count($uploaded_proofs), [$uploaded_proofs]);

        // First let's loop through and grab the proof numbers of the uploaded proofs
        $proof_numbers_uploaded = [];
        $proof_suffixes = config('proofgen.thumbnails');
        $proof_suffixes = array_map(function($item) {
            return $item['suffix'];
        }, $proof_suffixes);
        foreach($uploaded_proofs as $uploaded_proof) {
            $proof_number = pathinfo($uploaded_proof, PATHINFO_FILENAME);
            $file_extension = pathinfo($uploaded_proof, PATHINFO_EXTENSION);
            $proof_number = str_replace($file_extension, '', $proof_number);

            foreach($proof_suffixes as $proof_suffix) {
                $proof_number = str_replace($proof_suffix, '', $proof_number);
            }

            $proof_numbers_uploaded[$proof_number][] = $uploaded_proof;
        }

        // Now we'll loop through the $proof_numbers_uploaded array and check if we have a Photo record for it
        // as well as ensure that the count of proofs uploaded for that particular proof number is equal to
        // the count of suffixes
        foreach($proof_numbers_uploaded as $proof_number => $uploaded_proofs) {
            $photo = $this->photos()->where('proof_number', $proof_number)->first();
            if($photo) {
                // Check if we have the same number of proofs uploaded as there are suffixes
                if(count($uploaded_proofs) === count($proof_suffixes)) {
                    // Set the proofs_uploaded_at to now()
                    $photo->proofs_uploaded_at = now();
                    $photo->save();
                } else {
                    // if the count is off we'll need to set the uploaded_at to null
                    if($photo->proofs_uploaded_at) {
                        Log::debug('Setting proofs_uploaded_at to null for photo: '.$photo->id.' due to missing proofs on filesystem');
                        $photo->proofs_uploaded_at = null;
                        $photo->save();
                    }
                }
            } else {
                Log::debug('Uploaded proof number '.$proof_number.' not found in photos for this class in database: '.$this->id);
            }
        }

        return $proof_numbers_uploaded;
    }

    public function pendingProofUploads(): array
    {
        $remote_proofs_path = '/'.$this->remote_proofs_path;
        if( ! Storage::disk('remote_proofs')->exists($remote_proofs_path)){
            Storage::disk('remote_proofs')->makeDirectory($remote_proofs_path);
        }

        $path_resolver = app(PathResolver::class);
        // Run a dry run of the rsync to determine what files need to be uploaded
        $command = $this->rsyncProofsCommand(true);
        // Log::debug('Pending proofs upload rsync command: '.$command);
        exec($command, $output, $returnCode);

        $pending_proofs = [];

        foreach ($output as $line) {
            $line = trim($line);

            if (!empty($line) && str_starts_with(strtolower($line), strtolower($this->show_folder))) {
                $parts = explode('/', $line);
                $fileName = end($parts);

                if (!empty($fileName) && str_ends_with($fileName, '.jpg')) {
                    $pending_proofs[] = $path_resolver->normalizePath($this->proofs_path.'/'.$fileName);
                }
            }
        }

        // Get Photo records
        $photos = $this->photos()->whereNotNull('proofs_generated_at')->get();
        // Anything that is in this collection but not in the $pending_proofs array is already uploaded and we'll
        // update the proofs_uploaded_at field
        // Log::debug('Pending proof uploads', ['pending_proofs' => $pending_proofs]);
        foreach($photos as $photo) {
            $thumbnail_sizes = config('proofgen.thumbnails');
            $thumbnail_sizes = array_map(function($item) {
                return $item['suffix'];
            }, $thumbnail_sizes);
            $found_count = 0;
            foreach($thumbnail_sizes as $thumbnail_size) {
                $proofs_path = $this->proofs_path.'/'.$photo->proof_number.$thumbnail_size.'.'.$photo->file_type;
                if( ! in_array($proofs_path, $pending_proofs)) {
                    $found_count++;
                }
            }
            if($found_count === count($thumbnail_sizes)) {
                if( ! $photo->proofs_uploaded_at) {
                    $photo->proofs_uploaded_at = now();
                }
                if( ! $photo->proofs_generated_at) {
                    $photo->proofs_generated_at = now();
                }
                if($photo->isDirty()) {
                    $photo->save();
                }
            }
            else {
                if($photo->proofs_uploaded_at) {
                    Log::debug('Setting proofs_uploaded_at to null for photo: '.$photo->id);
                    $photo->proofs_uploaded_at = null;
                    $photo->save();
                }
            }
        }

        return $pending_proofs;
    }
}
