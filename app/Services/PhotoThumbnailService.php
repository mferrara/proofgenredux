<?php

namespace App\Services;

use App\Models\Photo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Builds cached `data:image/jpeg;base64,...` URIs for proof thumbnails without
 * decoding or re-encoding the bytes on disk.
 *
 * This replaces the old Intervention `Image::read(...)->toJpeg(...)` calls that
 * the table/grid partials made while rendering every row. The thumbnail files
 * are already JPEGs written by the proof pipeline, so we only need to read the
 * bytes and base64 them.
 */
class PhotoThumbnailService
{
    /**
     * Cache namespace. Bumped when the cache payload shape/path logic changes;
     * kept distinct from the legacy "thumbnails3-" keys.
     */
    private const CACHE_PREFIX = 'photo_thumbs_v1_';

    public function __construct(private PathResolver $pathResolver) {}

    public function path(Photo $photo, string $size = 'small'): ?string
    {
        $class = $photo->showClass;
        if ($class === null) {
            return null;
        }

        $suffix = config("proofgen.thumbnails.{$size}.suffix");
        if (! is_string($suffix) || $suffix === '') {
            return null;
        }

        return $this->pathResolver->normalizePath(
            $this->pathResolver->getProofThumbnailPath(
                $class->show_id,
                $class->name,
                $photo->proof_number.'.'.$photo->file_type,
                $suffix,
            )
        );
    }

    /** Return the existing JPEG without decoding or re-encoding its bytes. */
    public function dataUri(Photo $photo, string $size = 'small'): ?string
    {
        $path = $this->path($photo, $size);
        if ($path === null) {
            return null;
        }

        $cacheKey = $this->cacheKey($photo, $path);

        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $disk = Storage::disk('fullsize');

            if (! $disk->exists($path)) {
                return null;
            }

            $bytes = $disk->get($path);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($bytes) || $bytes === '') {
            return null;
        }

        // The thumbnail already is a JPEG. Keep the original bytes intact and
        // only wrap them in a data URI — no decode/re-encode pass.
        $dataUri = 'data:image/jpeg;base64,'.base64_encode($bytes);

        Cache::put($cacheKey, $dataUri, now()->addMinutes(60));

        return $dataUri;
    }

    /**
     * Cache key that busts whenever the photo is updated or its proofs are
     * (re)generated. Null results are never stored, so a missing thumbnail is
     * retried on the next render.
     */
    private function cacheKey(Photo $photo, string $path): string
    {
        $generatedAt = $photo->proofs_generated_at?->getTimestamp();
        $updatedAt = $photo->updated_at?->getTimestamp();

        return self::CACHE_PREFIX.md5($path.'|'.$generatedAt.'|'.$updatedAt);
    }
}
