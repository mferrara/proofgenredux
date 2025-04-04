<?php

namespace App\Models;

use App\Services\PathResolver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
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

    protected $casts = [
        'id' => 'string',
        'show_class_id' => 'string',
        'sha1' => 'string',
        'file_type' => 'string',
        'proofs_generated_at' => 'datetime',
        'proofs_uploaded_at' => 'datetime',
        'web_image_generated_at' => 'datetime',
        'web_image_uploaded_at' => 'datetime',
    ];

    // Model events
    protected static function boot()
    {
        parent::boot();

        static::creating(function (Photo $model) {
            $model->id = (string) $model->show_class_id . '_' . $model->proof_number;
        });

        static::created(function (Photo $model) {
            // Check if we have a sha1 hash for this photo
            $file_contents = null;
            if (empty($model->sha1)) {
                $file_contents = $model->getFileContents();
                $model->sha1 = sha1($file_contents);
                $model->save();
            }

            // Check if we have a metadata record for this photo
            if (empty($model->metadata)) {
                $metadata = $model->createMetadataRecord($file_contents);
            }

            // Check if the proofs are already generated
            $proofs_found = $model->checkPathForProofs();

            // Check if the web image is already generated
            $web_image_found = $model->checkPathForWebImage();
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
        });
    }

    public function createMetadataRecord(?string $file_contents = null): PhotoMetadata
    {
        if($file_contents === null) {
            $file_contents = $this->getFileContents();
        }

        // Get exif data from file contents with native PHP
        $exif_data = exif_read_data($this->full_path, 'EXIF', true);

        /** @var PhotoMetadata $metadata */
        $metadata = $this->metadata()->create([
            'photo_id' => $this->id,
            'file_size' => strlen($file_contents),
        ]);
        $metadata->fillFromExifDataArray($exif_data);
        $metadata->save();

        return $metadata;
    }

    public function getFullPathAttribute(): string
    {
        return config('proofgen.fullsize_home_dir') . '/' . $this->relative_path;
    }

    public function getRelativePathAttribute(): string
    {
        return str_replace('_', '/', $this->show_class_id) . '/originals/' . $this->proof_number . '.' . $this->file_type;
    }

    public function getProofsPathAttribute(): string
    {
        $path_resolver = app(PathResolver::class);
        $show_name = explode('_', $this->show_class_id)[0];
        $class_name = explode('_', $this->show_class_id)[1];

        return $path_resolver->getProofsPath($show_name, $class_name);
    }

    public function getAbsoluteProofsPathAttribute(): string
    {
        $path_resolver = app(PathResolver::class);

        return config('proofgen.fullsize_home_dir') . '/' . $path_resolver->normalizePath($this->proofs_path);
    }

    public function showClass(): BelongsTo
    {
        return $this->belongsTo(ShowClass::class, 'show_class_id', 'id');
    }

    public function metadata(): HasOne
    {
        return $this->hasOne(PhotoMetadata::class, 'photo_id', 'id');
    }

    public function getFileContents(): ?string
    {
        return file_get_contents($this->full_path);
    }

    public function expectedThumbnailFilenames()
    {
        $thumbnails = [];
        foreach (config('proofgen.thumbnails') as $size => $values) {
            $suffix = $values['suffix'];
            $expected_filename = $this->proof_number . $suffix . '.' . $this->file_type;
            $thumbnails[] = $expected_filename;
        }

        return $thumbnails;
    }

    public function deleteLocalProofs(): void
    {
        $proofs_path = $this->absolute_proofs_path;

        foreach($this->expectedThumbnailFilenames() as $filename) {
            $expected_proof_path = $proofs_path . '/' . $filename;
            if (file_exists($expected_proof_path)) {
                \Log::debug('Deleting proof: ' . $expected_proof_path);
                unlink($expected_proof_path);
            }
        }

        $check = $this->checkPathForProofs();
        if( ! $check) {
            $this->proofs_generated_at = null;
            $this->proofs_uploaded_at = null;
            $this->save();
        }
    }

    public function deleteLocalWebImage(): void
    {
        $expected_web_image_path = $this->expectedWebImageFilePath();
        if ($this->checkPathForWebImage()) {
            \Log::debug('Deleting web image: ' . $expected_web_image_path);
            unlink($expected_web_image_path);
        }

        if ($this->web_image_generated_at !== null) {
            $this->web_image_generated_at = null;
            $this->web_image_uploaded_at = null;
            $this->save();
        }
    }

    public function expectedWebImageFilePath(): string
    {
        $path_resolver = app(PathResolver::class);
        $web_images_path = $path_resolver->getWebImagesPath($this->showClass->show->name, $this->showClass->name);
        $web_images_path = config('proofgen.fullsize_home_dir') . '/' . $path_resolver->normalizePath($web_images_path);

        $expected_filename = $this->proof_number . '_web.' . $this->file_type;
        return $web_images_path . '/' . $expected_filename;
    }

    public function checkPathForWebImage(): bool
    {
        $expected_web_image_path = $this->expectedWebImageFilePath();

        if (file_exists($expected_web_image_path)) {

            if($this->web_image_generated_at === null) {
                $this->web_image_generated_at = Carbon::createFromTimestamp(filemtime($expected_web_image_path));
                $this->save();
            }

            return true;
        }

        return false;
    }

    public function checkPathForProofs(): false|array
    {
        $path_resolver = app(PathResolver::class);
        $proofs_path = $path_resolver->getProofsPath($this->showClass->show->name, $this->showClass->name);
        $proofs_path = config('proofgen.fullsize_home_dir').'/'.$path_resolver->normalizePath($proofs_path);

        $proofs_found = [];
        $expected_proof_count = count(config('proofgen.thumbnails'));
        foreach(config('proofgen.thumbnails') as $size => $values) {
            $suffix = $values['suffix'];
            $expected_filename = $this->proof_number.$suffix.'.'.$this->file_type;
            $expected_proof_path = $proofs_path.'/'.$expected_filename;

            // Determine if the $expected_proof_path exists, if so, determine the modified time using native php functions
            // and compare it to the modified time of the original file
            if (file_exists($expected_proof_path)) {
                $proof_modified_time = filemtime($expected_proof_path);
                $proofs_found[$expected_proof_path] = $proof_modified_time;
            }
        }

        if($expected_proof_count === count($proofs_found)) {
            // If we have all the proofs, set the proofs_generated_at to the earliest modified time
            if($this->proofs_generated_at === null) {
                $existing_timestamp = Carbon::createFromTimestamp($proofs_found[array_key_first($proofs_found)]);
                \Log::debug('Making proofs_generated_at for photo: '.$this->id.' from '.$existing_timestamp);
                $this->proofs_generated_at = $existing_timestamp;
                $this->save();
            }
        }

        if(count($proofs_found) === 0) {
            return false;
        }

        return $proofs_found;
    }
}
