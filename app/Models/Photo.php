<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Intervention\Image\Laravel\Facades\Image;

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

    // Model events
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->id = (string) $model->show_class_id . '_' . $model->proof_number;
        });

        static::created(function ($model) {
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
        return str_replace('_', '/', $this->show_class_id).'/originals/' . $this->proof_number.'.'.$this->file_type;
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
}
