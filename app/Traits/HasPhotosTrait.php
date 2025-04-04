<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

trait HasPhotosTrait
{
    public function photosProofed(): HasMany|HasManyThrough
    {
        return $this->photos()->whereNotNull('proofs_generated_at');
    }

    public function photosNotProofed(): HasMany|HasManyThrough
    {
        return $this->photos()->whereNull('proofs_generated_at');
    }

    public function photosProofsUploaded(): HasMany|HasManyThrough
    {
        return $this->photos()->whereNotNull('proofs_uploaded_at');
    }

    public function photosProofedNotUploaded(): HasMany|HasManyThrough
    {
        return $this->photos()->whereNotNull('proofs_generated_at')->whereNull('proofs_uploaded_at');
    }

    public function photosWebImaged(): HasMany|HasManyThrough
    {
        return $this->photos()->whereNotNull('web_image_generated_at');
    }

    public function photosNotWebImaged(): HasMany|HasManyThrough
    {
        return $this->photos()->whereNull('web_image_generated_at');
    }

    public function photosWebImagesUploaded(): HasMany|HasManyThrough
    {
        return $this->photos()->whereNotNull('web_image_uploaded_at');
    }

    public function photosWebImagedNotUploaded(): HasMany|HasManyThrough
    {
        return $this->photos()->whereNotNull('web_image_generated_at')->whereNull('web_image_uploaded_at');
    }
}
