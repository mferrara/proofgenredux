<?php

namespace App\Models;

use App\Services\PathResolver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Log;

class Photo extends Model
{
    protected $table = 'photos';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [
        'created_at',
        'updated_at',
    ];

    /**
     * In-memory show/class identity for photos created before a ShowClass row
     * exists (for example a direct Image::importPhoto() call). Callers set this
     * before save() so the created hook and the path accessors never have to
     * guess where the underscore boundaries are in the composite show_class_id.
     * The showClass relation stays authoritative whenever it resolves.
     */
    protected ?string $importShowId = null;

    protected ?string $importClassName = null;

    protected $casts = [
        'id' => 'string',
        'show_class_id' => 'string',
        'sha1' => 'string',
        'sha1_grandfathered' => 'boolean',
        'archive_path' => 'string',
        'archive_sha1' => 'string',
        'archive_size' => 'integer',
        'file_type' => 'string',
        'original_filename' => 'string',
        'proof_thm_key' => 'string',
        'proof_std_key' => 'string',
        'web_image_key' => 'string',
        'high_res_image_key' => 'string',
        'archived_at' => 'datetime',
        'proofs_generated_at' => 'datetime',
        'proofs_uploaded_at' => 'datetime',
        'web_image_generated_at' => 'datetime',
        'web_image_uploaded_at' => 'datetime',
        'highres_image_generated_at' => 'datetime',
        'highres_image_uploaded_at' => 'datetime',
    ];

    // Model events
    protected static function boot()
    {
        parent::boot();

        static::creating(function (Photo $model) {
            $model->id = (string) $model->show_class_id.'_'.$model->proof_number;

            // Discovery also creates photos. Hash before INSERT so a duplicate
            // cannot leave an incomplete row when the unique constraint rejects it.
            if (! config('testing.skip_file_operations') && empty($model->sha1)) {
                $hash = hash_file('sha1', $model->full_path);
                if ($hash === false) {
                    throw new \RuntimeException('Cannot hash original: '.$model->full_path);
                }
                $model->sha1 = $hash;
            }
        });

        static::created(function (Photo $model) {
            if (config('testing.skip_file_operations')) {
                return;
            }

            if (empty($model->metadata)) {
                $model->createMetadataRecord();
            }

            // Each of these has the side effect of stamping *_generated_at when
            // a matching file is found on disk; the return values aren't used here.
            $model->checkPathForProofs();
            $model->checkPathForWebImage();
            $model->checkPathForHighresImage();
        });

        static::updating(function (Photo $model) {
            // If our proofs_generated_at is changing to a non-null value we'll want to null out the proofs_uploaded_at
            // if there is a value so that it'll be re-uploaded
            if ($model->isDirty('proofs_generated_at') && $model->proofs_generated_at !== null) {
                // If proofs_generated_at is _before_ proofs_uploaded_at, we don't need to null the proofs_uploaded_at
                // value because we're probably back-setting a value from a previously generated file found in the directory
                if ($model->proofs_uploaded_at !== null && $model->proofs_generated_at > $model->proofs_uploaded_at) {
                    $model->proofs_uploaded_at = null;
                }
            }

            // If our web_image_generated_at is changing to a non-null value we'll want to null out the web_image_uploaded_at
            // if there is a value so that it'll be re-uploaded
            if ($model->isDirty('web_image_generated_at') && $model->web_image_generated_at !== null) {
                // If web_image_generated_at is _before_ web_image_uploaded_at, we don't need to null the web_image_uploaded_at
                // value because we're probably back-setting a value from a previously generated file found in the directory
                if ($model->web_image_uploaded_at !== null && $model->web_image_generated_at > $model->web_image_uploaded_at) {
                    $model->web_image_uploaded_at = null;
                }
            }

            // If our highres_image_generated_at is changing to a non-null value we'll want to null out the highres_image_uploaded_at
            // if there is a value so that it'll be re-uploaded
            if ($model->isDirty('highres_image_generated_at') && $model->highres_image_generated_at !== null) {
                // If highres_image_generated_at is _before_ highres_image_uploaded_at, we don't need to null the highres_image_uploaded_at
                // value because we're probably back-setting a value from a previously generated file found in the directory
                if ($model->highres_image_uploaded_at !== null && $model->highres_image_generated_at > $model->highres_image_uploaded_at) {
                    $model->highres_image_uploaded_at = null;
                }
            }
        });
    }

    public function createMetadataRecord(?string $file_contents = null): PhotoMetadata
    {
        // Metadata needs the byte count, not another full copy of the original.
        $fileSize = $file_contents === null ? filesize($this->full_path) : strlen($file_contents);
        if ($fileSize === false) {
            throw new \RuntimeException('Cannot read original size: '.$this->full_path);
        }

        // Read EXIF directly from the file.
        $exif_data = exif_read_data($this->full_path, 'EXIF', true);

        /** @var PhotoMetadata $metadata */
        $metadata = $this->metadata()->create([
            'photo_id' => $this->id,
            'file_size' => $fileSize,
        ]);
        if ($exif_data !== false) {
            $metadata->fillFromExifDataArray($exif_data);
        } else {
            Log::debug('Exif data not found for photo: '.$this->id.'/'.$this->proof_number);
        }
        $metadata->save();

        return $metadata;
    }

    public function getFullPathAttribute(): string
    {
        return config('proofgen.fullsize_home_dir').'/'.$this->relative_path;
    }

    public function getRelativePathAttribute(): string
    {
        [$show, $class] = $this->resolveShowAndClass();

        return $show.'/'.$class.'/originals/'.$this->proof_number.'.'.$this->file_type;
    }

    public function getProofsPathAttribute(): string
    {
        [$show, $class] = $this->resolveShowAndClass();

        return app(PathResolver::class)->getProofsPath($show, $class);
    }

    /**
     * Explicit show/class identity for callers that create a Photo directly,
     * before a ShowClass row exists. Set before save() so the created hook and
     * the path accessors resolve paths without splitting the composite
     * show_class_id at an underscore.
     */
    public function setShowClassContext(string $showId, string $className): static
    {
        $this->importShowId = $showId;
        $this->importClassName = $className;

        return $this;
    }

    /**
     * Resolve the real show/class names. The showClass relation is authoritative;
     * the explicit import context is the fallback for unsaved/pre-relation rows.
     *
     * @return array{0: string, 1: string}
     */
    protected function resolveShowAndClass(): array
    {
        $showClass = $this->showClass;

        if ($showClass !== null) {
            return [$showClass->show_id, $showClass->name];
        }

        if ($this->importShowId !== null && $this->importClassName !== null) {
            return [$this->importShowId, $this->importClassName];
        }

        throw new \RuntimeException(
            'Unable to resolve show/class for photo '.($this->id ?: '(unsaved)')
            .'. Load the showClass relation or call setShowClassContext() before reading derived paths.'
        );
    }

    public function getAbsoluteProofsPathAttribute(): string
    {
        $path_resolver = app(PathResolver::class);

        return config('proofgen.fullsize_home_dir').'/'.$path_resolver->normalizePath($this->proofs_path);
    }

    public function showClass(): BelongsTo
    {
        return $this->belongsTo(ShowClass::class, 'show_class_id', 'id');
    }

    public function metadata(): HasOne
    {
        return $this->hasOne(PhotoMetadata::class, 'photo_id', 'id');
    }

    public function getProofThmObjectKeyAttribute(): ?string
    {
        return $this->proof_thm_key;
    }

    public function getProofStdObjectKeyAttribute(): ?string
    {
        return $this->proof_std_key;
    }

    public function getWebImageObjectKeyAttribute(): ?string
    {
        return $this->web_image_key;
    }

    public function getHighResImageObjectKeyAttribute(): ?string
    {
        return $this->high_res_image_key;
    }

    public function getObjectKeysAttribute(): array
    {
        return [
            'proof_thm' => $this->proof_thm_key,
            'proof_std' => $this->proof_std_key,
            'web_image' => $this->web_image_key,
            'high_res_image' => $this->high_res_image_key,
        ];
    }

    public function getFileContents(): ?string
    {
        return file_get_contents($this->full_path);
    }

    public function expectedThumbnailFilenames(): array
    {
        // The derivative generators always encode JPEGs with the .jpg
        // extension; file_type describes the original and can be "jpeg".
        $thumbnails = [];
        foreach (config('proofgen.thumbnails') as $values) {
            $suffix = $values['suffix'];
            $thumbnails[] = $this->proof_number.$suffix.'.jpg';
        }

        return $thumbnails;
    }

    public function deleteLocalProofs(): void
    {
        $proofs_path = $this->absolute_proofs_path;

        foreach ($this->expectedThumbnailFilenames() as $filename) {
            $expected_proof_path = $proofs_path.'/'.$filename;
            if (file_exists($expected_proof_path)) {
                Log::debug('Deleting proof: '.$expected_proof_path);
                unlink($expected_proof_path);
            }
        }

        $check = $this->checkPathForProofs();
        if (! $check) {
            $this->proofs_generated_at = null;
            $this->proofs_uploaded_at = null;
            $this->save();
        }
    }

    public function deleteLocalWebImage(): void
    {
        $expected_web_image_path = $this->expectedWebImageFilePath();
        if ($this->checkPathForWebImage()) {
            Log::debug('Deleting web image: '.$expected_web_image_path);
            unlink($expected_web_image_path);
        }

        if ($this->web_image_generated_at !== null) {
            $this->web_image_generated_at = null;
            $this->web_image_uploaded_at = null;
            $this->save();
        }
    }

    public function deleteLocalHighresImage(): void
    {
        $expected_highres_image_path = $this->expectedHighresImageFilePath();
        if ($this->checkPathForHighresImage()) {
            Log::debug('Deleting highres image: '.$expected_highres_image_path);
            unlink($expected_highres_image_path);
        }

        if ($this->highres_image_generated_at !== null) {
            $this->highres_image_generated_at = null;
            $this->highres_image_uploaded_at = null;
            $this->save();
        }
    }

    public function expectedWebImageFilePath(): string
    {
        [$show, $class] = $this->resolveShowAndClass();
        $path_resolver = app(PathResolver::class);
        $web_images_path = $path_resolver->getWebImagesPath($show, $class);
        $web_images_path = config('proofgen.fullsize_home_dir').'/'.$path_resolver->normalizePath($web_images_path);

        $expected_filename = $this->proof_number.config('proofgen.web_images.suffix').'.jpg';

        return $web_images_path.'/'.$expected_filename;
    }

    public function checkPathForWebImage(): bool
    {
        $expected_web_image_path = $this->expectedWebImageFilePath();

        if (file_exists($expected_web_image_path)) {

            if ($this->web_image_generated_at === null) {
                $this->web_image_generated_at = Carbon::createFromTimestamp(filemtime($expected_web_image_path));
                $this->save();
            }

            return true;
        }

        return false;
    }

    public function expectedHighresImageFilePath(): string
    {
        [$show, $class] = $this->resolveShowAndClass();
        $path_resolver = app(PathResolver::class);
        $highres_images_path = $path_resolver->getHighresImagesPath($show, $class);
        $highres_images_path = config('proofgen.fullsize_home_dir').'/'.$path_resolver->normalizePath($highres_images_path);

        $expected_filename = $this->proof_number.config('proofgen.highres_images.suffix').'.jpg';

        return $highres_images_path.'/'.$expected_filename;
    }

    public function checkPathForHighresImage(): bool
    {
        $expected_highres_image_path = $this->expectedHighresImageFilePath();

        if (file_exists($expected_highres_image_path)) {

            if ($this->highres_image_generated_at === null) {
                $this->highres_image_generated_at = Carbon::createFromTimestamp(filemtime($expected_highres_image_path));
                $this->save();
            }

            return true;
        }

        return false;
    }

    public function checkPathForProofs(): false|array
    {
        $proofs_path = $this->absolute_proofs_path;

        $proofs_found = [];
        $expected_proof_count = count(config('proofgen.thumbnails'));
        foreach (config('proofgen.thumbnails') as $values) {
            $suffix = $values['suffix'];
            // Derivative proofs are always .jpg, even for .jpeg originals.
            $expected_filename = $this->proof_number.$suffix.'.jpg';
            $expected_proof_path = $proofs_path.'/'.$expected_filename;

            // Determine if the $expected_proof_path exists, if so, determine the modified time using native php functions
            // and compare it to the modified time of the original file
            if (file_exists($expected_proof_path)) {
                $proof_modified_time = filemtime($expected_proof_path);
                $proofs_found[$expected_proof_path] = $proof_modified_time;
            }
        }

        if ($expected_proof_count === count($proofs_found)) {
            // If we have all the proofs, set the proofs_generated_at to the earliest modified time
            if ($this->proofs_generated_at === null) {
                $existing_timestamp = Carbon::createFromTimestamp($proofs_found[array_key_first($proofs_found)]);
                Log::debug('Making proofs_generated_at for photo: '.$this->id.' from '.$existing_timestamp);
                $this->proofs_generated_at = $existing_timestamp;
                $this->save();
            }
        }

        if (count($proofs_found) === 0) {
            return false;
        }

        return $proofs_found;
    }
}
