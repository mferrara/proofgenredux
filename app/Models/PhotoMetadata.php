<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Intervention\Image\Image;

class PhotoMetadata extends Model
{
    protected $table = 'photo_metadata';
    protected $primaryKey = 'photo_id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [
        'created_at',
        'updated_at',
    ];
    protected $casts = [
        'photo_id' => 'string',
        'file_size' => 'integer',
        'height' => 'integer',
        'width' => 'integer',
    ];

    public function photo(): BelongsTo
    {
        return $this->belongsTo(Photo::class, 'photo_id', 'id');
    }

    public function getRouteKeyName(): string
    {
        return 'photo_id';
    }

    public function fillFromInterventionImage(Image $image): void
    {
        $orientation = null;
        $size_object = $image->size();

        $this->height = $size_object->height();
        $this->width = $size_object->width();

        if($this->width === $this->height && ($this->width && $this->height)) {
            $orientation = 'sq';
        } elseif($this->width > $this->height) {
            $orientation = 'la';
        } elseif($this->width < $this->height) {
            $orientation = 'po';
        }
        $this->orientation = $orientation;
        $this->aspect_ratio = $size_object->aspectRatio();
    }
}
