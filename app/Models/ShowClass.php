<?php

namespace App\Models;

use App\Jobs\Photo\GenerateHighresImage;
use App\Jobs\Photo\GenerateThumbnails;
use App\Jobs\Photo\GenerateWebImage;
use App\Jobs\Photo\ImportPhoto;
use App\Proofgen\Utility;
use App\Services\Delivery\DeliveryTargetDisks;
use App\Services\Delivery\DeliveryTargetResolver;
use App\Services\PathResolver;
use App\Services\PhotoArchiveService;
use App\Services\Transport\RsyncCommandBuilder;
use App\Services\Transport\UploadConfigurationException;
use App\Services\Transport\UploadSyncResult;
use App\Services\Transport\UploadSyncService;
use App\Traits\HasPhotosTrait;
use App\Traits\RsyncHandlerTrait;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;

class ShowClass extends Model
{
    use HasFactory;
    use HasPhotosTrait;
    use RsyncHandlerTrait;

    protected $table = 'show_classes';

    protected $primaryKey = 'id';

    public $incrementing = false;

    // ShowClass.id is a varchar like "22Buck_007" — without this, eager-loads
    // (e.g. ->with('photos')) coerce IDs to 0. See sibling note on Show.
    protected $keyType = 'string';

    protected $guarded = [
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'id' => 'string',
        'show_id' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Model events
    protected static function boot()
    {
        parent::boot();

        static::created(function (ShowClass $model) {
            // Skip file operations during tests if configured
            if (config('testing.skip_file_operations')) {
                return;
            }

            // Check the ShowClass directory for a directory named 'originals'
            // if it's there, and it has images in it, we'll import them
            // into the database
            $model->importExistingPhotosFromOriginalsDirectory();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function getFullPathAttribute(): string
    {
        return config('proofgen.fullsize_home_dir').'/'.$this->relative_path;
    }

    public function getRelativePathAttribute(): string
    {
        return $this->show_id.'/'.$this->name;
    }

    public function getOriginalsPathAttribute()
    {
        return $this->relative_path.'/originals';
    }

    public function getFullOriginalsPathAttribute()
    {
        return config('proofgen.fullsize_home_dir').'/'.$this->originals_path;
    }

    public function getRemoteWebImagesPathAttribute()
    {
        $path_resolver = app(PathResolver::class);
        // Remote path uses the ferraraphoto-side slug (which defaults to show->id when no override is set).
        $remote_web_images_path = $path_resolver->getRemoteWebImagesPath($this->show->ferraraphoto_slug, $this->name);

        return $path_resolver->normalizePath($remote_web_images_path);
    }

    public function getWebImagesPathAttribute(): string
    {
        $path_resolver = app(PathResolver::class);
        $web_images_path = $path_resolver->getWebImagesPath($this->show->id, $this->name);

        return $path_resolver->normalizePath($web_images_path);
    }

    public function getFullWebImagesPathAttribute()
    {
        $path_resolver = app(PathResolver::class);
        $web_images_path = $this->web_images_path;

        return $path_resolver->getAbsolutePath($web_images_path, config('proofgen.fullsize_home_dir'));
    }

    public function getHighresImagesPathAttribute(): string
    {
        $path_resolver = app(PathResolver::class);
        $highres_images_path = $path_resolver->getHighresImagesPath($this->show->id, $this->name);

        return $path_resolver->normalizePath($highres_images_path);
    }

    public function getFullHighresImagesPathAttribute()
    {
        $path_resolver = app(PathResolver::class);
        $highres_images_path = $this->highres_images_path;

        return $path_resolver->getAbsolutePath($highres_images_path, config('proofgen.fullsize_home_dir'));
    }

    public function getRemoteHighresImagesPathAttribute()
    {
        $path_resolver = app(PathResolver::class);
        $remote_highres_images_path = $path_resolver->getRemoteHighresImagesPath($this->show->ferraraphoto_slug, $this->name);

        return $path_resolver->normalizePath($remote_highres_images_path);
    }

    public function getProofsPathAttribute()
    {
        $path_resolver = app(PathResolver::class);
        $proofs_path = $path_resolver->getProofsPath($this->show->id, $this->name);

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
        $remote_proofs_path = $path_resolver->getRemoteProofsPath($this->show->ferraraphoto_slug, $this->name);

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

    /**
     * Returns relationship Builders (not materialized Collections) so views
     * can call ->count() and get a SQL COUNT(*) instead of SELECT *.
     */
    public function processingCounts(): array
    {
        return [
            'photos_imported' => $this->photos(),
            'photos_proofed' => $this->photosProofed(),
            'photos_pending_proofs' => $this->photosNotProofed(),
            'photos_proofs_uploaded' => $this->photosProofsUploaded(),
            'photos_pending_proof_uploads' => $this->photosProofedNotUploaded(),
            'photos_web_images_generated' => $this->photosWebImaged(),
            'photos_pending_web_images' => $this->photosNotWebImaged(),
            'photos_web_images_uploaded' => $this->photosWebImagesUploaded(),
            'photos_pending_web_image_uploads' => $this->photosWebImagedNotUploaded(),
            'photos_highres_images_generated' => $this->photosHighresImaged(),
            'photos_pending_highres_images' => $this->photosNotHighresImaged(),
            'photos_highres_images_uploaded' => $this->photosHighresImagesUploaded(),
            'photos_pending_highres_image_uploads' => $this->photosHighresImagedNotUploaded(),
        ];
    }

    public function getImagesPendingImport(): array
    {
        $contents = Utility::getContentsOfPath($this->relative_path, false);

        $images = [];
        if (isset($contents['images'])) {
            $images = $contents['images'];
        }

        return $images;
    }

    public function importPendingImages(): array
    {
        $images = $this->getImagesPendingImport();
        $queued = [];
        /** @var FileAttributes $image */
        foreach ($images as $image) {
            // No pre-allocated proof number — the resolver inside the job decides.
            ImportPhoto::dispatch($image->path())->onQueue('processing');

            if (isset($photo->is_new) && $photo->is_new === true) {
                $queued[] = $photo;
            }
        }

        return $queued;
    }

    /**
     * Parses the file path to extract the proof number and file type to determine if we have
     * this photo in the database already. If not, it creates a new Photo record. (Which, the Photo model starts a
     * string of methods to generate the sha1 hash, metadata, and check for proofs and web images)
     */
    public function importPreviouslyProcessedImagesFromOriginalsPath(string $file_path): Photo
    {
        $proof_number = $this->proofNumberFromPath($file_path);
        $photo_id = $this->photoIdFromProofNumber($proof_number);
        $file_type = pathinfo($file_path, PATHINFO_EXTENSION);
        $file_type = strtolower($file_type);

        // Check if we have this database record
        $photo = $this->photos()->where('id', $photo_id)->first();
        if (! $photo) {
            // If the photo doesn't exist, create it
            // Open the file to generate it's sha1 and pass to PhotoMetadata
            // to generate the metadata
            $photo = new Photo;
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
        $thumbnail_sizes = array_map(function ($item) {
            return $item['suffix'];
        }, $thumbnail_sizes);
        $thumbnail_sizes[] = '_web';

        foreach ($thumbnail_sizes as $thumbnail_size) {
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
        $show_class = new \App\Proofgen\ShowClass($this->show->id, $this->name, $pathResolver);
        $originals_images = $show_class->getImportedImages();

        $new_records = 0;
        foreach ($originals_images as $image) {
            /** @var FileAttributes $image */
            $file_path = $image->path();
            $photo = $this->importPreviouslyProcessedImagesFromOriginalsPath($file_path);
            if (isset($photo->is_new) && $photo->is_new === true) {
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
     * Reset a class back to "ingest pending":
     *   1. Delete derived files (web/highres/proofs) — regenerable, plain delete is fine.
     *   2. Move each originals/{proof}.{ext} directly back to the base class folder under
     *      a unique randomized filename so the proof number is released. Move the matching
     *      archive copy alongside, including for orphan originals that have no Photo row.
     *   3. Delete the Photo rows, but only for originals whose own release succeeded.
     *
     * Per-photo work is wrapped in try/catch so a single bad file doesn't abort the whole
     * reset. A failed release keeps its Photo row and original in place; because the
     * derivative files were already cleared, that retained row has its derivative state
     * reset to null so it never claims deleted derivatives are ready. File operations
     * cannot be in a DB transaction.
     */
    public function resetPhotos(): array
    {
        $stats = ['derivatives_deleted' => 0, 'originals_renamed' => 0, 'orphan_originals_renamed' => 0, 'photo_rows_deleted' => 0, 'failures' => 0];

        $stats['derivatives_deleted'] += $this->deleteDirectoryFiles($this->web_images_path, 'web image', $stats['failures']);
        $stats['derivatives_deleted'] += $this->deleteDirectoryFiles($this->highres_images_path, 'highres image', $stats['failures']);
        $stats['derivatives_deleted'] += $this->deleteDirectoryFiles($this->proofs_path, 'proof', $stats['failures']);

        $originals_path = $this->originals_path;
        $released_proof_numbers = [];
        $failed_proof_numbers = [];

        if (Storage::disk('fullsize')->exists($originals_path)) {
            $archiveService = app(PhotoArchiveService::class);
            $contents = Utility::getContentsOfPath($originals_path);
            $images = $contents['images'] ?? [];
            Log::debug('Found '.count($images).' originals to release');

            foreach ($images as $image) {
                /** @var FileAttributes $image */
                $file_path = $image->path();
                $proof_number = pathinfo($file_path, PATHINFO_FILENAME);
                try {
                    $renamed = $this->releaseOriginal($file_path, $archiveService);
                    if ($renamed['had_photo_record']) {
                        $stats['originals_renamed']++;
                        $released_proof_numbers[$proof_number] = true;
                    } else {
                        $stats['orphan_originals_renamed']++;
                    }
                } catch (\Throwable $e) {
                    $stats['failures']++;
                    $failed_proof_numbers[$proof_number] = true;
                    Log::error('resetPhotos: failed to release original; '.$file_path.' — '.$e->getMessage());
                }
            }
        }

        // Only drop a row once its own original (and archive) release succeeded.
        // A failed release, a row with no original on disk, or a failed delete all
        // stay behind, with derivative state neutralized to match the cleared files.
        foreach ($this->photos()->get() as $photo) {
            /** @var Photo $photo */
            if (isset($released_proof_numbers[$photo->proof_number]) && ! isset($failed_proof_numbers[$photo->proof_number])) {
                try {
                    if (! $photo->delete()) {
                        throw new \RuntimeException('Photo row deletion was refused: '.$photo->id);
                    }
                    $stats['photo_rows_deleted']++;
                } catch (\Throwable $e) {
                    $stats['failures']++;
                    $this->clearDerivativeState($photo);
                    Log::error('resetPhotos: failed to delete photo row '.$photo->id.' — '.$e->getMessage());
                }

                continue;
            }

            if (! isset($failed_proof_numbers[$photo->proof_number])) {
                // The row has no matching original file at all: a missing source.
                // Keep it and count the failure instead of silently dropping it.
                $stats['failures']++;
            }
            $this->clearDerivativeState($photo);
            Log::warning('resetPhotos: keeping photo row '.$photo->id.' (original not released)');
        }

        Log::debug('resetPhotos: complete', $stats);

        return $stats;
    }

    private function deleteDirectoryFiles(string $path, string $kind, int &$failures): int
    {
        if (! Storage::disk('fullsize')->exists($path)) {
            return 0;
        }
        $contents = Utility::getContentsOfPath($path);
        $files = $contents['images'] ?? [];
        Log::debug('Found '.count($files).' '.$kind.'s to delete in '.$path);
        $deleted = 0;
        foreach ($files as $file) {
            /** @var FileAttributes $file */
            $file_path = $file->path();
            try {
                if (! Storage::disk('fullsize')->delete($file_path)) {
                    throw new \RuntimeException('File deletion failed: '.$file_path);
                }
                $deleted++;
            } catch (\Throwable $e) {
                $failures++;
                Log::error('resetPhotos: failed to delete '.$kind.' '.$file_path.' — '.$e->getMessage());
            }
        }

        return $deleted;
    }

    /**
     * Move {originals}/{proof}.{ext} directly to a unique randomized ingest filename in
     * the base class folder, then move the matching archive copy to that same basename.
     * Returns ['had_photo_record' => bool] so the caller can stat.
     *
     * If the archive step fails, the original is moved back to its canonical path (and
     * the archive back to its old basename if it had already moved) so the retained row
     * and its archive metadata keep pointing at real files.
     */
    private function releaseOriginal(string $file_path, PhotoArchiveService $archiveService): array
    {
        $proof_number = pathinfo($file_path, PATHINFO_FILENAME);
        $extension = pathinfo($file_path, PATHINFO_EXTENSION);
        $photo_record = $this->photos()->where('proof_number', $proof_number)->first();

        $old_basename = $proof_number.'.'.$extension;
        $new_basename = $this->uniqueRandomBasename($extension);
        $randomized_path = $this->relative_path.'/'.$new_basename;
        $fullsize = Storage::disk('fullsize');

        if (! $fullsize->move($file_path, $randomized_path)) {
            throw new \RuntimeException("Failed to move original {$file_path} to {$randomized_path}");
        }
        if (! $fullsize->exists($randomized_path)) {
            throw new \RuntimeException("Original move verification failed for {$file_path}");
        }

        try {
            if ($photo_record) {
                $archiveService->movePhotoArchiveToFilename($photo_record, $new_basename);
            } else {
                $archiveService->movePhysicalArchive($this->show_id, $this->name, $old_basename, $new_basename);
            }
        } catch (\Throwable $e) {
            if ($fullsize->exists($randomized_path)) {
                try {
                    if (! $fullsize->move($randomized_path, $file_path)) {
                        throw new \RuntimeException('Restore failed; original remains at '.$randomized_path);
                    }
                } catch (\Throwable $restoreError) {
                    Log::error('resetPhotos: failed to restore original after archive failure; '.$file_path.' — '.$restoreError->getMessage());
                }
            }

            // If the archive had already moved, pull it back to the old basename.
            try {
                $archiveService->movePhysicalArchive($this->show_id, $this->name, $new_basename, $old_basename);
            } catch (\Throwable $archiveRestoreError) {
                Log::error('resetPhotos: failed to restore archive after failure; '.$old_basename.' — '.$archiveRestoreError->getMessage());
            }

            throw $e;
        }

        return ['had_photo_record' => $photo_record !== null];
    }

    private function uniqueRandomBasename(string $extension): string
    {
        $fullsize = Storage::disk('fullsize');
        do {
            $stem = sha1(uniqid('', true));
            $candidate = $stem.'.'.$extension;
        } while ($fullsize->exists($this->relative_path.'/'.$candidate));

        return $candidate;
    }

    /**
     * The derivative files were just cleared class-wide. A photo we're retaining
     * (failed release / missing source) must not keep claiming they're ready.
     * Remote upload timestamps remain valid: reset only removes local derivatives.
     */
    private function clearDerivativeState(Photo $photo): void
    {
        $photo->forceFill([
            'proofs_generated_at' => null,
            'web_image_generated_at' => null,
            'highres_image_generated_at' => null,
        ])->saveQuietly();
    }

    /**
     * Queue thumbnails for an array of Photos
     */
    public function queueThumbnailGeneration(array|Collection $photos): int
    {
        $queued = 0;
        foreach ($photos as $photo) {
            if (! Storage::disk('fullsize')->exists($photo->relative_path)) {
                continue;
            }
            GenerateThumbnails::dispatch($photo->id, $this->proofs_path)->onQueue('thumbnails');
            $queued++;
        }

        return $queued;
    }

    /**
     * Queue web images for an array of Photos
     */
    public function queueWebImageGeneration(array|Collection $photos): int
    {
        // Check if web images generation is enabled
        if (! config('proofgen.generate_web_images.enabled', true)) {
            return 0;
        }

        $queued = 0;
        foreach ($photos as $photo) {
            if (! Storage::disk('fullsize')->exists($photo->relative_path)) {
                continue;
            }
            GenerateWebImage::dispatch($photo->id, $this->web_images_path)->onQueue('thumbnails');
            $queued++;
        }

        return $queued;
    }

    /**
     * Queue highres images for an array of Photos
     */
    public function queueHighresImageGeneration(array|Collection $photos): int
    {
        // Check if highres images generation is enabled
        if (! config('proofgen.generate_highres_images.enabled', true)) {
            return 0;
        }

        $queued = 0;
        foreach ($photos as $photo) {
            if (! Storage::disk('fullsize')->exists($photo->relative_path)) {
                continue;
            }
            GenerateHighresImage::dispatch($photo->id, $this->highres_images_path)->onQueue('thumbnails');
            $queued++;
        }

        return $queued;
    }

    /**
     * Queue all imported Photos for thumbnail generation
     */
    public function regenerateProofs(): int
    {
        // Remove the local proof files for this class and reset the proofs_generated_at
        foreach ($this->photosProofed()->get() as $photo) {
            if (! Storage::disk('fullsize')->exists($photo->relative_path)) {
                continue;
            }
            /** @var Photo $photo */
            $photo->deleteLocalProofs();
        }

        return $this->queueThumbnailGeneration($this->photos()->get());
    }

    public function regenerateWebImages(): int
    {
        // Remove the local web images for this class and reset the web_image_generated_at
        foreach ($this->photosWebImaged()->get() as $photo) {
            if (! Storage::disk('fullsize')->exists($photo->relative_path)) {
                continue;
            }
            /** @var Photo $photo */
            $photo->deleteLocalWebImage();
        }

        return $this->queueWebImageGeneration($this->photos()->get());
    }

    public function webImagePendingPhotos(): int
    {
        return $this->queueWebImageGeneration($this->photosNotWebImaged()->get());
    }

    public function regenerateHighresImages(): int
    {
        // Remove the local highres images for this class and reset the highres_image_generated_at
        foreach ($this->photosHighresImaged()->get() as $photo) {
            if (! Storage::disk('fullsize')->exists($photo->relative_path)) {
                continue;
            }
            /** @var Photo $photo */
            $photo->deleteLocalHighresImage();
        }

        return $this->queueHighresImageGeneration($this->photos()->get());
    }

    public function highresImagePendingPhotos(): int
    {
        return $this->queueHighresImageGeneration($this->photosNotHighresImaged()->get());
    }

    public function rsyncWebImagesCommand($dry_run = false): string
    {
        $local = app(PathResolver::class)->getAbsolutePath($this->web_images_path, config('proofgen.fullsize_home_dir').'/').'/';

        $target = app(DeliveryTargetResolver::class)->current($this->show);

        return RsyncCommandBuilder::build($local, $target->directory('web_images'), $this->name, $dry_run === true, $target);
    }

    public function rsyncProofsCommand($dry_run = false): string
    {
        $local = app(PathResolver::class)->getAbsolutePath($this->proofs_path, config('proofgen.fullsize_home_dir').'/').'/';

        $target = app(DeliveryTargetResolver::class)->current($this->show);

        return RsyncCommandBuilder::build($local, $target->directory('proofs'), $this->name, $dry_run === true, $target);
    }

    public function rsyncHighresImagesCommand($dry_run = false): string
    {
        $local = app(PathResolver::class)->getAbsolutePath($this->highres_images_path, config('proofgen.fullsize_home_dir').'/').'/';

        $target = app(DeliveryTargetResolver::class)->current($this->show);

        return RsyncCommandBuilder::build($local, $target->directory('highres_images'), $this->name, $dry_run === true, $target);
    }

    /**
     * Get the local proof images from the fullsize disk
     *
     * @return FileAttributes[]
     */
    public function localProofFiles(): array /* \League\Flysystem\FileAttributes[] */
    {
        if (! Storage::disk('fullsize')->exists($this->proofs_path)) {
            Storage::disk('fullsize')->makeDirectory($this->proofs_path);
        }

        $photos = Utility::getContentsOfPath($this->proofs_path);
        $photos = $photos['images'] ?? [];

        // Get the proof suffixes from the config
        $thumbnail_sizes = config('proofgen.thumbnails');
        $thumbnail_sizes = array_map(function ($item) {
            return $item['suffix'];
        }, $thumbnail_sizes);

        // Loop through $photos to determine if one of these suffixes is in the filename
        // If it is, we'll add it to the $proofs array
        $proofs = [];
        foreach ($photos as $photo) {
            /** @var FileAttributes $photo */
            // Check if the proof number is in the filename
            foreach ($thumbnail_sizes as $thumbnail_size) {
                if (str_contains($photo->path(), $thumbnail_size)) {
                    $proofs[] = $photo;
                    break;
                }
            }
        }

        return $proofs;
    }

    /**
     * Operation to sync the local proof files with the database
     */
    public function localProofsSync(): true
    {
        $proofs = $this->localProofFiles();

        // Log::debug('localProofsSync found '.count($proofs).' proofs on filesystem');

        // Get the suffixes for the thumbnail files
        $thumbnail_sizes = config('proofgen.thumbnails');
        $thumbnail_sizes = array_map(function ($item) {
            return $item['suffix'];
        }, $thumbnail_sizes);

        $existing_proofed_proof_numbers = [];
        foreach ($proofs as $photo) {
            $file_path = $photo->path();
            $last_modified = $photo->lastModified();
            $proof_number = pathinfo($file_path, PATHINFO_FILENAME);
            $proof_number = explode('.', $proof_number);
            $proof_number = array_shift($proof_number);

            // Remove the suffix from the proof number
            foreach ($thumbnail_sizes as $thumbnail_size) {
                $proof_number = str_replace($thumbnail_size, '', $proof_number);
            }

            $existing_proofed_proof_numbers[$proof_number][] = $last_modified;
        }

        $suffix_count = count($thumbnail_sizes);
        foreach ($existing_proofed_proof_numbers as $proof_number => $last_modified_array) {

            // First, ensure that we have the same number of $last_modified_array values as there are $suffixes
            // If not, there's missing proofs and we'll want to un-set the proofs_generated_at on the corresponding
            // Photo record (if it exists)
            if (count($last_modified_array) !== $suffix_count) {
                $photo_record = $this->photos()->where('id', $this->id.'_'.$proof_number)->first();
                if ($photo_record) {
                    $photo_record->proofs_generated_at = null;
                    $photo_record->save();
                }

                continue;
            }

            // Check if we have this database record
            $photo_record = $this->photos()->where('id', $this->id.'_'.$proof_number)->first();
            if ($photo_record) {

                // Get the higher of the last modified times
                $last_modified = max($last_modified_array);

                if ($last_modified !== $photo_record->proofs_generated_at) {
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
        if ($photos_not_included->count()) {
            foreach ($photos_not_included as $photo) {
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
        if (! Storage::disk('fullsize')->exists($this->web_images_path)) {
            Storage::disk('fullsize')->makeDirectory($this->web_images_path);
        }

        $photos = Utility::getContentsOfPath($this->web_images_path);
        $photos = $photos['images'] ?? [];

        $web_images = [];
        foreach ($photos as $photo) {
            // If there is a '_web' in the filename, we'll add it to the web_images array
            /** @var FileAttributes $photo */
            if (str_contains($photo->path(), '_web')) {
                $web_images[] = $photo;
            }
        }

        return $web_images;
    }

    /**
     * Operation to sync the local web image files with the database
     */
    public function localWebImageSync(): true
    {
        $web_images = $this->localWebImageFiles();

        // Log::debug('localWebImageSync found '.count($web_images).' web images on filesystem');

        $proof_numbers_included_in_web_images = [];
        foreach ($web_images as $photo) {
            $file_path = $photo->path();
            $last_modified = $photo->lastModified();
            $proof_number = pathinfo($file_path, PATHINFO_FILENAME);
            $proof_number = explode('.', $proof_number);
            $proof_number = array_shift($proof_number);
            $proof_number = str_replace('_web', '', $proof_number);

            $proof_numbers_included_in_web_images[] = $proof_number;

            // Check if we have this database record
            $photo_record = $this->photos()->where('id', $this->id.'_'.$proof_number)->first();
            if ($photo_record) {
                if ($last_modified !== $photo_record->web_image_generated_at) {
                    // If the last modified date is different, update it
                    $photo_record->web_image_generated_at = Carbon::createFromTimestamp($last_modified);
                    $photo_record->save();
                }
            }
        }

        // Let's ensure that we don't have any Photo records that indicate their web images are generated but didn't
        // exist in the filesystem
        $photos_not_included = $this->photos()->whereNotNull('web_image_generated_at')->whereNotIn('proof_number', $proof_numbers_included_in_web_images)->get();
        if ($photos_not_included->count()) {
            foreach ($photos_not_included as $photo) {
                Log::debug('Reverting web image generation/upload state for photo: '.$photo->id);
                $photo->web_image_generated_at = null;
                // We're specifically not resetting the web_image_uploaded_at here because they _might_ be uploaded,
                // but just not locally existing on this filesystem
                $photo->save();
            }
        }

        return true;
    }

    /**
     * Run the rsync upload for this class's web images.
     *
     * On a successful transfer the rsync-reported manifest (transferred plus
     * unchanged files) is reported and the matching complete Photo records are
     * stamped. A non-zero rsync exit throws before any timestamp is touched,
     * so retries reconcile stamps instead of leaving a partial state behind.
     */
    public function webImageUploads(): array
    {
        $result = $this->runClassSync('web_images', false);

        $this->applyClassSyncEvidence($this, 'web_images', $result->syncedFiles, [], $result->transferredFiles, false);

        return $this->classSyncPaths('web_images', $result->syncedFiles);
    }

    public function highresImageUploads(): array
    {
        $result = $this->runClassSync('highres_images', false);

        $this->applyClassSyncEvidence($this, 'highres_images', $result->syncedFiles, [], $result->transferredFiles, false);

        return $this->classSyncPaths('highres_images', $result->syncedFiles);
    }

    /**
     * Dry run: report the web images rsync would transfer and clear stale
     * upload stamps for them. A dry-run never stamps (a would-be transfer is
     * not evidence of remote success).
     */
    public function pendingWebImageUploads(): array
    {
        $result = $this->runClassSync('web_images', true);

        $this->applyClassSyncEvidence($this, 'web_images', [], $result->pendingFiles, $result->transferredFiles, true);

        return $this->classSyncPaths('web_images', $result->pendingFiles);
    }

    /**
     * Dry run: report the highres images rsync would transfer and clear stale
     * upload stamps for them.
     */
    public function pendingHighresImageUploads(): array
    {
        $result = $this->runClassSync('highres_images', true);

        $this->applyClassSyncEvidence($this, 'highres_images', [], $result->pendingFiles, $result->transferredFiles, true);

        return $this->classSyncPaths('highres_images', $result->pendingFiles);
    }

    /**
     * Run the rsync upload for this class's proofs.
     *
     * A photo is only stamped when every configured proof suffix exists
     * locally (the manifest is only consulted after rsync exits 0), so a
     * half-generated proof pair never produces an uploaded timestamp. The
     * return shape stays keyed by proof number for the UI/tests.
     *
     * @return array<string, array<int, string>>
     */
    public function proofUploads()
    {
        $result = $this->runClassSync('proofs', false);

        $this->applyClassSyncEvidence($this, 'proofs', $result->syncedFiles, [], $result->transferredFiles, false);

        return $this->classProofPaths($result->syncedFiles);
    }

    /**
     * Dry run: report the proofs rsync would transfer and clear stale upload
     * stamps for the affected photos. Missing local variants are never turned
     * into an uploaded timestamp.
     */
    public function pendingProofUploads(): array
    {
        $result = $this->runClassSync('proofs', true);

        $this->applyClassSyncEvidence($this, 'proofs', [], $result->pendingFiles, $result->transferredFiles, true);

        return $this->classSyncPaths('proofs', $result->pendingFiles);
    }

    /**
     * Run one class-level rsync through the shared transport service, making
     * sure the remote destination directory exists first.
     *
     * A missing destination path throws for real uploads (before Storage or
     * rsync is touched) so a chain cannot silently advance to metadata. A
     * read-only pending check may still return an empty result instead, since
     * an unconfigured optional kind (e.g. highres) is legitimately "nothing
     * pending" for the UI.
     */
    private function runClassSync(string $syncType, bool $dryRun): UploadSyncResult
    {
        $localBase = match ($syncType) {
            'proofs' => $this->proofs_path,
            'web_images' => $this->web_images_path,
            'highres_images' => $this->highres_images_path,
            default => throw new \InvalidArgumentException("Unknown sync type: {$syncType}"),
        };

        // Gallery's handshake result when this show has one, otherwise the
        // local SFTP settings. Either way the directory is already scoped to
        // the remote show slug; only the class folder is appended.
        $target = app(DeliveryTargetResolver::class)->current($this->show);
        $remoteShowDir = $target->directory($syncType);

        if ($remoteShowDir === '') {
            Log::error('SFTP '.$syncType.' path not configured - cannot upload '.$this->id);

            if (! $dryRun) {
                throw UploadConfigurationException::missingDestination(
                    $syncType,
                    $this->syncTypeConfigKey($syncType),
                    'class '.$this->id,
                );
            }

            return new UploadSyncResult($syncType, $dryRun, [], [], []);
        }

        app(DeliveryTargetDisks::class)->ensureDirectory($target, $syncType, $this->name);

        $local = app(PathResolver::class)->getAbsolutePath($localBase, config('proofgen.fullsize_home_dir').'/').'/';

        return app(UploadSyncService::class)->sync($syncType, $local, $remoteShowDir, $this->name, $dryRun, $target);
    }
}
