<?php

namespace App\Models;

use App\Proofgen\Utility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

        static::created(function ($model) {
            // Check the ShowClass directory for a directory named 'originals'
            // if it's there, and it has images in it, we'll import them
            // into the database
            $originals_path = $model->relative_path . '/originals';
            $files = Utility::getContentsOfPath($originals_path, false);

            $images = [];
            if(isset($files['images']))
                $images = $files['images'];

            foreach($images as $file) {
                /** @var FileAttributes $file */
                $file_path = $file->path();
                $photo = $model->importPhotoFromPath($file_path);
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function getFullPathAttribute(): string
    {
        return config('proofgen.fullsize_home_dir') . '/' . $this->id;
    }

    public function getRelativePathAttribute(): string
    {
        return str_replace('_', '/', $this->id);
    }

    public function show(): BelongsTo
    {
        return $this->belongsTo(Show::class, 'show_id', 'id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class, 'show_class_id', 'id');
    }

    public function importPhotoFromPath(string $file_path): Photo
    {
        $proof_number = pathinfo($file_path, PATHINFO_FILENAME);
        $proof_number = explode('.', $proof_number);
        $proof_number = array_shift($proof_number);

        $file_type = pathinfo($file_path, PATHINFO_EXTENSION);
        $file_type = strtolower($file_type);

        // Check if we have this database record
        $photo = $this->photos()->where('id', $this->id.'_'.$proof_number)->first();
        if( ! $photo) {
            // If the photo doesn't exist, create it
            // Open the file to generate it's sha1 and pass to PhotoMetadata
            // to generate the metadata
            $photo = new Photo();
            $photo->show_class_id = $this->id;
            $photo->proof_number = $proof_number;
            $photo->file_type = $file_type;
            $photo->save();
        }

        return $photo;
    }
}
