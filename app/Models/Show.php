<?php

namespace App\Models;

use App\Jobs\Photo\ImportPhoto;
use App\Proofgen\Utility;
use App\Services\PathResolver;
use App\Services\Transport\RsyncCommandBuilder;
use App\Services\Transport\UploadConfigurationException;
use App\Services\Transport\UploadSyncResult;
use App\Services\Transport\UploadSyncService;
use App\Traits\HasPhotosTrait;
use App\Traits\RsyncHandlerTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;

class Show extends Model
{
    use HasFactory;
    use HasPhotosTrait;
    use RsyncHandlerTrait;

    protected $table = 'shows';

    protected $primaryKey = 'id';

    public $incrementing = false;

    // Without this, Eloquent's default keyType='int' coerces string IDs to 0 in
    // eager-load IN() clauses (e.g. `select * from shows where id in (0)`), so
    // any `->with('show')` on a related model returns null. Lazy loading happened
    // to work because it queries with the raw value.
    protected $keyType = 'string';

    protected $guarded = [
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'id' => 'string',
        'ferraraphoto_show_slug' => 'string',
        'storage_profile_id' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    /**
     * The slug to use when building paths on the ferraraphoto host. Defaults to
     * the proofgen show id (the historical "matched-by-convention" slug);
     * operator can override via the ferraraphoto_show_slug column when the
     * match drifts (e.g. proofgen show "22Buck" but ferraraphoto show
     * "buck-show-2024").
     */
    public function getFerraraphotoSlugAttribute(): string
    {
        $override = $this->attributes['ferraraphoto_show_slug'] ?? null;
        if (is_string($override) && trim($override) !== '') {
            return trim($override);
        }

        return (string) $this->id;
    }

    public function classes(): HasMany
    {
        return $this->hasMany(ShowClass::class, 'show_id', 'id');
    }

    public function storageProfile(): BelongsTo
    {
        return $this->belongsTo(StorageProfile::class, 'storage_profile_id', 'id');
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
        return config('proofgen.fullsize_home_dir').'/'.$this->id;
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
            foreach ($class_directories as $class_folder) {
                $class_folder = str_replace($model->id.'/', '', $class_folder);
                if (! $model->hasClass($class_folder)) {
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
        $highres_images_pending_upload = $this->pendingHighresImageUploads();

        return [
            'images_pending_upload' => $images_pending_upload,
            'web_images_pending_upload' => $web_images_pending_upload,
            'highres_images_pending_upload' => $highres_images_pending_upload,
        ];
    }

    /**
     * Get the rsync command for proofs for all classes in this show
     *
     * @param  bool  $dry_run
     */
    public function rsyncProofsCommand($dry_run = false): string
    {
        $resolver = app(PathResolver::class);
        $local = $resolver->getAbsolutePath($resolver->getShowProofsPath($this->id), config('proofgen.fullsize_home_dir')).'/';

        // Local path uses the proofgen show id (matches the on-disk directory).
        // Remote subpath uses the ferraraphoto_slug accessor (honors operator override; falls back to id).
        return RsyncCommandBuilder::build($local, config('proofgen.sftp.path'), $resolver->getShowRemoteProofsPath($this->ferraraphoto_slug), $dry_run === true);
    }

    /**
     * Get the rsync command for web images for all classes in this show
     */
    public function rsyncWebImagesCommand($dry_run = false): string
    {
        $resolver = app(PathResolver::class);
        $local = $resolver->getAbsolutePath($resolver->getShowWebImagesPath($this->id), config('proofgen.fullsize_home_dir')).'/';

        return RsyncCommandBuilder::build($local, config('proofgen.sftp.web_images_path'), $resolver->getShowRemoteWebImagesPath($this->ferraraphoto_slug), $dry_run === true);
    }

    /**
     * Get the rsync command for highres images for all classes in this show
     */
    public function rsyncHighresImagesCommand($dry_run = false): string
    {
        $resolver = app(PathResolver::class);
        $local = $resolver->getAbsolutePath($resolver->getShowHighresImagesPath($this->id), config('proofgen.fullsize_home_dir')).'/';

        return RsyncCommandBuilder::build($local, config('proofgen.sftp.highres_images_path'), $resolver->getShowRemoteHighresImagesPath($this->ferraraphoto_slug), $dry_run === true);
    }

    /**
     * Check for pending proof uploads across all classes in this show.
     *
     * This is a dry run: it reports files rsync would transfer and clears
     * stale upload timestamps for them. It never stamps an upload timestamp.
     */
    public function pendingProofUploads(): array
    {
        $result = $this->runShowSync('proofs', true);

        $this->applyShowLevelSyncEvidence('proofs', [], $result->pendingFiles, $result->transferredFiles, true);

        return $this->showLevelSyncPaths('proofs', $result->pendingFiles);
    }

    /**
     * Upload pending proofs across all classes in this show.
     *
     * On success the rsync-reported manifest (transferred plus unchanged
     * files) is stamped onto complete Photo records. A non-zero rsync exit
     * throws before any timestamp is touched.
     */
    public function proofUploads(): array
    {
        $result = $this->runShowSync('proofs', false);

        $this->applyShowLevelSyncEvidence('proofs', $result->syncedFiles, [], $result->transferredFiles, false);

        return $this->showLevelSyncPaths('proofs', $result->syncedFiles);
    }

    /**
     * Check for pending web image uploads across all classes in this show.
     */
    public function pendingWebImageUploads(): array
    {
        $result = $this->runShowSync('web_images', true);

        $this->applyShowLevelSyncEvidence('web_images', [], $result->pendingFiles, $result->transferredFiles, true);

        return $this->showLevelSyncPaths('web_images', $result->pendingFiles);
    }

    /**
     * Upload pending web images across all classes in this show.
     */
    public function webImageUploads(): array
    {
        $result = $this->runShowSync('web_images', false);

        $this->applyShowLevelSyncEvidence('web_images', $result->syncedFiles, [], $result->transferredFiles, false);

        return $this->showLevelSyncPaths('web_images', $result->syncedFiles);
    }

    /**
     * Check for pending highres image uploads across all classes in this show.
     */
    public function pendingHighresImageUploads(): array
    {
        $result = $this->runShowSync('highres_images', true);

        $this->applyShowLevelSyncEvidence('highres_images', [], $result->pendingFiles, $result->transferredFiles, true);

        return $this->showLevelSyncPaths('highres_images', $result->pendingFiles);
    }

    /**
     * Upload pending highres images across all classes in this show.
     */
    public function highresImageUploads(): array
    {
        $result = $this->runShowSync('highres_images', false);

        $this->applyShowLevelSyncEvidence('highres_images', $result->syncedFiles, [], $result->transferredFiles, false);

        return $this->showLevelSyncPaths('highres_images', $result->syncedFiles);
    }

    /**
     * Run one show-level rsync via the shared transport service, ensuring the
     * remote destination directory exists first.
     */
    private function runShowSync(string $syncType, bool $dryRun): UploadSyncResult
    {
        $resolver = app(PathResolver::class);

        [$localBase, $remoteBase, $remoteSubdir, $disk, $remoteDir] = match ($syncType) {
            'proofs' => [
                $resolver->getShowProofsPath($this->id),
                config('proofgen.sftp.path'),
                $resolver->getShowRemoteProofsPath($this->ferraraphoto_slug),
                'remote_proofs',
                '/'.$resolver->getShowRemoteProofsPath($this->ferraraphoto_slug),
            ],
            'web_images' => [
                $resolver->getShowWebImagesPath($this->id),
                config('proofgen.sftp.web_images_path'),
                $resolver->getShowRemoteWebImagesPath($this->ferraraphoto_slug),
                'remote_web_images',
                '/'.$resolver->getShowRemoteWebImagesPath($this->ferraraphoto_slug),
            ],
            'highres_images' => [
                $resolver->getShowHighresImagesPath($this->id),
                config('proofgen.sftp.highres_images_path'),
                $resolver->getShowRemoteHighresImagesPath($this->ferraraphoto_slug),
                'remote_highres_images',
                '/'.$resolver->getShowRemoteHighresImagesPath($this->ferraraphoto_slug),
            ],
            default => throw new \InvalidArgumentException("Unknown sync type: {$syncType}"),
        };

        $configKey = $this->syncTypeConfigKey($syncType);
        $remoteBase = trim((string) $remoteBase);

        if ($remoteBase === '') {
            // Real uploads must fail loudly before any Storage/rsync access.
            // Read-only pending checks may still report "nothing pending".
            Log::error('SFTP '.$syncType.' path not configured - cannot upload show '.$this->id);

            if (! $dryRun) {
                throw UploadConfigurationException::missingDestination($syncType, $configKey, 'show '.$this->id);
            }

            return new UploadSyncResult($syncType, $dryRun, [], [], []);
        }

        if (! Storage::disk($disk)->exists($remoteDir)) {
            Storage::disk($disk)->makeDirectory($remoteDir);
        }

        $local = $resolver->getAbsolutePath($localBase, config('proofgen.fullsize_home_dir')).'/';

        return app(UploadSyncService::class)->sync($syncType, $local, $remoteBase, (string) $remoteSubdir, $dryRun);
    }

    public function getImagesPendingImport(): array
    {
        $images = [];
        $seen = [];

        // Walk the show's immediate subdirectories (class folders) from disk rather
        // than the classes relation: an unregistered folder still has ingest files
        // that need to be surfaced. Each class folder is scanned non-recursively,
        // which inherently excludes originals/, _import_conflicts/, _graveyard/,
        // and any other nested housekeeping directory. Utility only returns actual
        // jpg/jpeg files (final extension, case-insensitive), so sidecars and
        // non-JPEG files are not discovered.
        foreach (Utility::getDirectoriesOfPath($this->relative_path) as $classRelativePath) {
            $classFolder = basename($classRelativePath);
            if ($classFolder === '' || str_starts_with($classFolder, '.') || in_array($classFolder, ['originals', '_import_conflicts', '_graveyard', '_conflicts'], true)) {
                continue;
            }

            $contents = Utility::getContentsOfPath($classRelativePath, false);
            foreach ($contents['images'] ?? [] as $image) {
                /** @var FileAttributes $image */
                $path = $image->path();
                if (isset($seen[$path])) {
                    continue;
                }
                $seen[$path] = true;
                $images[] = $image;
            }
        }

        // Keep the historical show-wide ordering (oldest modified first).
        usort($images, fn (FileAttributes $a, FileAttributes $b) => $a->lastModified() <=> $b->lastModified());

        return $images;
    }

    public function importPendingImages(): int
    {
        $images = $this->getImagesPendingImport();

        $processed = 0;
        if ($images) {
            foreach ($images as $image) {
                // No pre-allocated proof number — the resolver inside the job will decide
                // whether the file is a genuinely new raw upload (allocate then) or already
                // numbered / duplicate / collision (don't burn a number).
                ImportPhoto::dispatch($image->path())->onQueue('processing');
                $processed++;
            }
        }

        return $processed;
    }

    public function getNextProofNumber(): string
    {
        $show_folder = $this->id;

        $redis_key = 'available_proof_numbers_'.$show_folder;

        $redis_client = Redis::client();
        // Do we have a redis list with the $redis_key or, if we have one, but it's empty...
        if (! $redis_client->exists($redis_key) || $redis_client->llen($redis_key) === 0) {
            // Generate the proof numbers
            $proof_numbers = Utility::generateProofNumbers($show_folder, 10000);
            // Add the proof numbers to the redis list
            foreach ($proof_numbers as $available_proof_number) {
                $redis_client->rpush($redis_key, $available_proof_number);
            }
        }
        $proof_number = $redis_client->lpop($redis_key);

        // phpredis returns false (and a failed/misconfigured client can return
        // null) when there is nothing left to pop. Returning that from a
        // `string` method would TypeError deep inside the import; fail with a
        // message that names the show and list instead.
        if (! is_string($proof_number) || trim($proof_number) === '') {
            throw new \RuntimeException(sprintf(
                'Unable to allocate a proof number for show "%s": lpop on Redis list "%s" returned %s. '
                .'The available proof-number pool is empty or Redis is unavailable. No image has been imported.',
                $show_folder,
                $redis_key,
                $proof_number === false ? 'false' : get_debug_type($proof_number)
            ));
        }

        return $proof_number;
    }
}
