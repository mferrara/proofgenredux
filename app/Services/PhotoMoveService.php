<?php

namespace App\Services;

use App\Models\Photo;
use App\Models\ShowClass;
use Illuminate\Support\Facades\DB;

class PhotoMoveService
{
    public function movePhotos(array $photoIds, string $targetClassId): array
    {
        $results = [
            'success' => [],
            'errors' => [],
        ];

        foreach ($photoIds as $photoId) {
            try {
                DB::transaction(function () use ($photoId, $targetClassId, &$results) {
                    $photo = Photo::find($photoId);
                    if (! $photo) {
                        throw new \Exception("Photo not found: {$photoId}");
                    }

                    $targetClass = ShowClass::find($targetClassId);
                    if (! $targetClass) {
                        throw new \Exception("Target class not found: {$targetClassId}");
                    }

                    // Create new photo record
                    $newPhotoId = $targetClassId.'_'.$photo->proof_number;

                    // Check if photo with same proof number exists in target class
                    if (Photo::find($newPhotoId)) {
                        throw new \Exception("Photo with proof number {$photo->proof_number} already exists in target class");
                    }

                    // Move files
                    $this->movePhotoFiles($photo, $targetClass);

                    // Create new photo record without triggering boot events
                    $newPhoto = new Photo;
                    $newPhoto->id = $newPhotoId;
                    $newPhoto->proof_number = $photo->proof_number;
                    $newPhoto->show_class_id = $targetClassId;
                    $newPhoto->sha1 = $photo->sha1;
                    $newPhoto->file_type = $photo->file_type;
                    $newPhoto->proofs_generated_at = $photo->proofs_generated_at;
                    $newPhoto->proofs_uploaded_at = $photo->proofs_uploaded_at;
                    $newPhoto->web_image_generated_at = $photo->web_image_generated_at;
                    $newPhoto->web_image_uploaded_at = $photo->web_image_uploaded_at;
                    $newPhoto->highres_image_generated_at = $photo->highres_image_generated_at;
                    $newPhoto->highres_image_uploaded_at = $photo->highres_image_uploaded_at;

                    // Use saveQuietly to avoid triggering model events
                    $newPhoto->saveQuietly();

                    // Update metadata
                    if ($photo->metadata) {
                        $photo->metadata->update(['photo_id' => $newPhotoId]);
                    }

                    // Delete old photo record
                    $photo->delete();

                    $results['success'][] = $photo->proof_number;
                });
            } catch (\Exception $e) {
                $results['errors'][$photoId] = $e->getMessage();
            }
        }

        return $results;
    }

    private function movePhotoFiles(Photo $photo, ShowClass $targetClass): void
    {
        $pathResolver = app(PathResolver::class);

        // Move original
        $oldPath = $photo->full_path;
        // Replace the show/class path components
        $oldShowClassPath = str_replace('_', '/', $photo->show_class_id);
        $newShowClassPath = str_replace('_', '/', $targetClass->id);
        $newPath = str_replace($oldShowClassPath, $newShowClassPath, $oldPath);

        if (file_exists($oldPath)) {
            $this->ensureDirectoryExists(dirname($newPath));
            rename($oldPath, $newPath);
        }

        // Move proofs
        foreach ($photo->expectedThumbnailFilenames() as $filename) {
            $oldProofPath = $photo->absolute_proofs_path.'/'.$filename;
            $newProofPath = str_replace($oldShowClassPath, $newShowClassPath, $oldProofPath);
            if (file_exists($oldProofPath)) {
                $this->ensureDirectoryExists(dirname($newProofPath));
                rename($oldProofPath, $newProofPath);
            }
        }

        // Move web image
        $webSuffix = config('proofgen.web_images.suffix', '_web');
        $oldWebPath = config('proofgen.fullsize_home_dir').'/web_images/'.$oldShowClassPath.'/'.$photo->proof_number.$webSuffix.'.jpg';
        if (file_exists($oldWebPath)) {
            $newWebPath = str_replace($oldShowClassPath, $newShowClassPath, $oldWebPath);
            $this->ensureDirectoryExists(dirname($newWebPath));
            rename($oldWebPath, $newWebPath);
        }

        // Move highres image
        $highresSuffix = config('proofgen.highres_images.suffix', '_highres');
        $oldHighresPath = config('proofgen.fullsize_home_dir').'/highres_images/'.$oldShowClassPath.'/'.$photo->proof_number.$highresSuffix.'.jpg';
        if (file_exists($oldHighresPath)) {
            $newHighresPath = str_replace($oldShowClassPath, $newShowClassPath, $oldHighresPath);
            $this->ensureDirectoryExists(dirname($newHighresPath));
            rename($oldHighresPath, $newHighresPath);
        }
    }

    private function ensureDirectoryExists(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }
}
