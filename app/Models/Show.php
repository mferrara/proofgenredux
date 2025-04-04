<?php

namespace App\Models;

use App\Jobs\Photo\ImportPhoto;
use App\Proofgen\Utility;
use App\Services\PathResolver;
use App\Traits\HasPhotosTrait;
use App\Traits\RsyncHandlerTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

class Show extends Model
{
    use RsyncHandlerTrait;
    use HasPhotosTrait;

    protected $table = 'shows';
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

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function classes(): HasMany
    {
        return $this->hasMany(ShowClass::class, 'show_id', 'id');
    }

    public function photos(): HasManyThrough
    {
        return $this->hasManyThrough(
            Photo::class,
            ShowClass::class,
            'show_id', // Foreign key on ShowClass table
            'show_class_id', // Foreign key on Photo table
            'id', // Local key on Show table
            'id' // Local key on ShowClass table
        );
    }

    public function getFullPathAttribute(): string
    {
        return config('proofgen.fullsize_home_dir') . '/' . $this->id;
    }

    public function getRelativePathAttribute(): string
    {
        return $this->id;
    }

    // Model events
    protected static function boot()
    {
        parent::boot();

        static::created(function ($model) {
            // Check the show directory for subdirectories, where each subdirectory is a ShowClass where it's 'id' is
            // the subdirectory name, and it's name is the subdirectory name and import them (ie: create ShowClass
            // records where they don't exist)
            $class_directories = Utility::getDirectoriesOfPath($model->relative_path);
            foreach($class_directories as $class_folder) {
                $class_folder = str_replace($model->id.'/', '', $class_folder);
                if( ! $model->hasClass($class_folder)) {
                    // If the class doesn't exist, create it
                    $class = $model->addClass($class_folder);
                }
            }
        });
    }

    public function hasClass(string $class_name): bool
    {
        $class = $this->classes()->where('id', $this->id.'_'.$class_name)->first();
        if ($class) {
            return true;
        }
        return false;
    }

    public function addClass(string $class_folder)
    {
        return $this->classes()->create([
            'id' => $this->id.'_'.$class_folder,
            'name' => $class_folder,
        ]);
    }

    public function checkAllUploads(): array
    {
        $images_pending_upload = $this->pendingProofUploads();
        $web_images_pending_upload = $this->pendingWebImageUploads();

        return [
            'images_pending_upload' => $images_pending_upload,
            'web_images_pending_upload' => $web_images_pending_upload,
        ];
    }

    /**
     * Get the rsync command for proofs for all classes in this show
     *
     * @param bool $dry_run
     * @return string
     */
    public function rsyncProofsCommand($dry_run = false): string
    {
        $path_resolver = app(PathResolver::class);
        $show_proofs_path = $path_resolver->getShowProofsPath($this->id);
        $local_full_path = $path_resolver->getAbsolutePath($show_proofs_path, config('proofgen.fullsize_home_dir')) . '/';
        $dry_run = $dry_run === true ? '--dry-run' : '';
        $remote_proofs_path = $path_resolver->getShowRemoteProofsPath($this->id);

        return 'rsync -avz --delete '.$dry_run.' -e "ssh -i '.config('proofgen.sftp.private_key').'" '.
            $local_full_path.' forge@'.config('proofgen.sftp.host').':'.config('proofgen.sftp.path').
            $remote_proofs_path;
    }

    /**
     * Get the rsync command for web images for all classes in this show
     *
     * @param bool $dry_run
     * @return string
     */
    public function rsyncWebImagesCommand($dry_run = false): string
    {
        $path_resolver = app(PathResolver::class);
        $show_web_images_path = $path_resolver->getShowWebImagesPath($this->id);
        $local_full_path = $path_resolver->getAbsolutePath($show_web_images_path, config('proofgen.fullsize_home_dir')) . '/';
        $dry_run = $dry_run === true ? '--dry-run' : '';
        $remote_web_images_path = $path_resolver->getShowRemoteWebImagesPath($this->id);

        return 'rsync -avz --delete '.$dry_run.' -e "ssh -i '.config('proofgen.sftp.private_key').'" '.
            $local_full_path.' forge@'.config('proofgen.sftp.host').':'.config('proofgen.sftp.web_images_path').
            $remote_web_images_path;
    }

    /**
     * Check for pending proof uploads across all classes in this show
     * Also updates Photo records that are already uploaded
     *
     * @return array
     */
    public function pendingProofUploads(): array
    {
        $path_resolver = app(PathResolver::class);

        // Ensure the remote directory exists
        $remote_proofs_path = '/' . $path_resolver->getShowRemoteProofsPath($this->id);
        if (!Storage::disk('remote_proofs')->exists($remote_proofs_path)) {
            Storage::disk('remote_proofs')->makeDirectory($remote_proofs_path);
        }

        // Run a dry run of the rsync to determine what files need to be uploaded
        $command = $this->rsyncProofsCommand(true);
        exec($command, $output, $returnCode);

        // Use the trait to process the output and update database records
        $uploaded_paths = $this->processProofRsyncOutput($output, $this->id, true);

        // Check for any records that might have had proofs previously uploaded but the flag not set
        $this->postUploadProofExistenceAndUploadFlagCheck();

        return $uploaded_paths;
    }

    /**
     * Upload pending proofs across all classes in this show
     * Also updates Photo records with upload timestamp
     *
     * @return array
     */
    public function proofUploads(): array
    {
        $path_resolver = app(PathResolver::class);

        // Ensure the remote directory exists
        $remote_proofs_path = '/' . $path_resolver->getShowRemoteProofsPath($this->id);
        if (!Storage::disk('remote_proofs')->exists($remote_proofs_path)) {
            Storage::disk('remote_proofs')->makeDirectory($remote_proofs_path);
        }

        // Run the rsync command
        $command = $this->rsyncProofsCommand();
        exec($command, $output, $returnCode);

        // Use the trait to process the output and update database records
        $uploaded_paths = $this->processProofRsyncOutput($output, $this->id, false);

        // Check for any records that might have had proofs previously uploaded but the flag not set
        $this->postUploadProofExistenceAndUploadFlagCheck();

        return $uploaded_paths;
    }

    /**
     * Check for pending web image uploads across all classes in this show
     * Also updates Photo records that are already uploaded
     *
     * @return array
     */
    public function pendingWebImageUploads(): array
    {
        $path_resolver = app(PathResolver::class);
        $dry_run = true;

        // Ensure the remote directory exists
        $remote_web_images_path = '/' . $path_resolver->getShowRemoteWebImagesPath($this->id);
        if (!Storage::disk('remote_web_images')->exists($remote_web_images_path)) {
            Storage::disk('remote_web_images')->makeDirectory($remote_web_images_path);
        }

        // Run a dry run of the rsync to determine what files need to be uploaded
        $command = $this->rsyncWebImagesCommand($dry_run);
        exec($command, $output, $returnCode);

        // Use the trait to process the output and update database records
        $uploaded_files = $this->processWebImageRsyncOutput($output, $this->id, $dry_run);

        // Check for any records that might have had web images previously uploaded but the flag not set
        $this->postUploadWebImageExistenceAndUploadFlagCheck();

        return $uploaded_files;
    }

    /**
     * Upload pending web images across all classes in this show
     * Also updates Photo records with upload timestamp
     *
     * @return array
     */
    public function webImageUploads(): array
    {
        $path_resolver = app(PathResolver::class);
        $dry_run = false;

        // Ensure the remote directory exists
        $remote_web_images_path = '/' . $path_resolver->getShowRemoteWebImagesPath($this->id);
        if (!Storage::disk('remote_web_images')->exists($remote_web_images_path)) {
            Storage::disk('remote_web_images')->makeDirectory($remote_web_images_path);
        }

        // Run the rsync command
        $command = $this->rsyncWebImagesCommand();
        exec($command, $output, $returnCode);

        // Use the trait to process the output and update database records
        $uploaded_photos = $this->processWebImageRsyncOutput($output, $this->id, $dry_run);

        // Check for any records that might have had web images previously uploaded but the flag not set
        $this->postUploadWebImageExistenceAndUploadFlagCheck();

        return $uploaded_photos;
    }

    /**
     * After the rsync command has run, check for any photos that have proofs generated but are not flagged as
     * uploaded, if there are any matching records after the rsync that means either the proofs don't actually exist
     * or were already uploaded and not properly indicated in the database. So we'll aim to update/fix that here.
     *
     * @return void
     */
    protected function postUploadProofExistenceAndUploadFlagCheck(): void
    {
        $photos = $this->photosProofedNotUploaded()->get();
        /** @var Photo $photo */
        foreach($photos as $photo) {
            $has_proofs = $photo->checkPathForProofs();

            // If we have the actual proof files and the proofs_uploaded_at is null, set it to the current time
            if ($has_proofs && $photo->proofs_uploaded_at === null) {
                $photo->proofs_uploaded_at = now();
                $photo->save();
            }
        }
    }

    protected function postUploadWebImageExistenceAndUploadFlagCheck(): void
    {
        $photos = $this->photosWebImagedNotUploaded()->get();
        /** @var Photo $photo */
        foreach($photos as $photo) {
            $has_web_image = $photo->checkPathForWebImage();

            // If we have the actual web image and the web_image_uploaded_at is null, set it to the current time
            if ($has_web_image && $photo->web_image_uploaded_at === null) {
                $photo->web_image_uploaded_at = now();
                $photo->save();
            }
        }
    }

    public function getImagesPendingImport(): array
    {
        $contents = Utility::getContentsOfPath($this->relative_path, true);

        //\Log::debug(print_r($contents, true));

        $images = [];
        if(isset($contents['images']))
            $images = $contents['images'];

        // If there's images, filter out any that have '/originals/' in the path
        if (count($images)) {
            $images = array_filter($images, function ($image) {
                return !str_contains($image->path(), '/originals/');
            });
        }

        return $images;
    }

    public function importPendingImages(): int
    {
        $images = $this->getImagesPendingImport();

        $processed = 0;
        if ($images) {
            foreach ($images as $image) {
                ImportPhoto::dispatch($image->path(), $this->getNextProofNumber())->onQueue('processing');
                $processed++;
            }
        }

        return $processed;
    }

    public function getNextProofNumber(): string
    {
        $show_folder = $this->id;

        $redis_key = 'available_proof_numbers_' . $show_folder;

        $redis_client = Redis::client();
        // Do we have a redis list with the $redis_key or, if we have one, but it's empty...
        if (!$redis_client->exists($redis_key) || $redis_client->llen($redis_key) === 0) {
            // Generate the proof numbers
            $proof_numbers = Utility::generateProofNumbers($show_folder, 10000);
            // Add the proof numbers to the redis list
            foreach($proof_numbers as $available_proof_number) {
                $redis_client->rpush($redis_key, $available_proof_number);
            }
        }
        $proof_number = $redis_client->lpop($redis_key);

        return $proof_number;
    }
}
