<?php

namespace App\Services;

use App\Jobs\Photo\GenerateHighresImage;
use App\Jobs\Photo\GenerateThumbnails;
use App\Jobs\Photo\GenerateWebImage;
use App\Models\Photo;
use App\Models\ShowClass;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PhotoMoveService
{
    public function __construct(
        private ?PhotoArchiveService $photoArchiveService = null,
        private ?PathResolver $pathResolver = null,
    ) {
        $this->photoArchiveService ??= app(PhotoArchiveService::class);
        $this->pathResolver ??= app(PathResolver::class);
    }

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
                    $moveResult = $this->movePhotoFiles($photo, $targetClass);
                    $archiveMetadata = $this->photoArchiveService->movePhotoArchive(
                        $photo,
                        $targetClass,
                        $moveResult['original_absolute_path'] ?? null
                    );

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
                    if ($archiveMetadata) {
                        $newPhoto->archive_path = $archiveMetadata['archive_path'];
                        $newPhoto->archive_sha1 = $archiveMetadata['archive_sha1'];
                        $newPhoto->archive_size = $archiveMetadata['archive_size'];
                        $newPhoto->archived_at = $archiveMetadata['archived_at'];
                    }

                    // Use saveQuietly to avoid triggering model events
                    $newPhoto->saveQuietly();

                    // Update metadata
                    if ($photo->metadata) {
                        $photo->metadata->update(['photo_id' => $newPhotoId]);
                    }

                    // Delete old photo record
                    $photo->delete();

                    $this->dispatchRegenJobsForMissingDerivatives(
                        $newPhoto,
                        $targetClass,
                        $moveResult['missing_derivatives']
                    );

                    $results['success'][] = $photo->proof_number;
                });
            } catch (\Exception $e) {
                $results['errors'][$photoId] = $e->getMessage();
            }
        }

        return $results;
    }

    private function movePhotoFiles(Photo $photo, ShowClass $targetClass): array
    {
        $disk = Storage::disk('fullsize');

        [$sourceShow, $sourceClass] = $this->showClassParts($photo->show_class_id);
        $targetShow = $targetClass->show_id;
        $targetClassName = $targetClass->name;
        $proofNumber = $photo->proof_number;
        $extension = $photo->file_type;

        $oldOriginal = $this->normalize($this->pathResolver->getOriginalFilePath($sourceShow, $sourceClass, $proofNumber.'.'.$extension));
        $newOriginal = $this->normalize($this->pathResolver->getOriginalFilePath($targetShow, $targetClassName, $proofNumber.'.'.$extension));

        if (! $disk->exists($oldOriginal)) {
            throw new RuntimeException("Original file missing for photo {$photo->id}: {$oldOriginal}");
        }

        $disk->move($oldOriginal, $newOriginal);

        if (! $disk->exists($newOriginal)) {
            throw new RuntimeException("Original move failed for photo {$photo->id}: {$oldOriginal} -> {$newOriginal}");
        }

        $missingDerivatives = [
            'proofs' => false,
            'web' => false,
            'highres' => false,
        ];

        // Proofs: move every expected thumbnail that exists; if all are missing
        // and proofs were previously generated, queue regen.
        $proofsMovedCount = 0;
        foreach (config('proofgen.thumbnails') as $config) {
            $suffix = $config['suffix'];
            $filename = $proofNumber.$suffix.'.'.$extension;
            $oldProof = $this->normalize($this->pathResolver->getProofsPath($sourceShow, $sourceClass).'/'.$filename);
            $newProof = $this->normalize($this->pathResolver->getProofsPath($targetShow, $targetClassName).'/'.$filename);

            if ($disk->exists($oldProof)) {
                $disk->move($oldProof, $newProof);
                $proofsMovedCount++;
            }
        }
        if ($proofsMovedCount === 0 && $photo->proofs_generated_at !== null) {
            $missingDerivatives['proofs'] = true;
        }

        // Web image
        $webSuffix = config('proofgen.web_images.suffix', '_web');
        $webFilename = $proofNumber.$webSuffix.'.jpg';
        $oldWeb = $this->normalize($this->pathResolver->getWebImagesPath($sourceShow, $sourceClass).'/'.$webFilename);
        $newWeb = $this->normalize($this->pathResolver->getWebImagesPath($targetShow, $targetClassName).'/'.$webFilename);
        if ($disk->exists($oldWeb)) {
            $disk->move($oldWeb, $newWeb);
        } elseif ($photo->web_image_generated_at !== null) {
            $missingDerivatives['web'] = true;
        }

        // Highres image
        $highresSuffix = config('proofgen.highres_images.suffix', '_highres');
        $highresFilename = $proofNumber.$highresSuffix.'.jpg';
        $oldHighres = $this->normalize($this->pathResolver->getHighresImagesPath($sourceShow, $sourceClass).'/'.$highresFilename);
        $newHighres = $this->normalize($this->pathResolver->getHighresImagesPath($targetShow, $targetClassName).'/'.$highresFilename);
        if ($disk->exists($oldHighres)) {
            $disk->move($oldHighres, $newHighres);
        } elseif ($photo->highres_image_generated_at !== null) {
            $missingDerivatives['highres'] = true;
        }

        return [
            'original_absolute_path' => $this->absolutePath($newOriginal),
            'missing_derivatives' => $missingDerivatives,
        ];
    }

    private function dispatchRegenJobsForMissingDerivatives(Photo $newPhoto, ShowClass $targetClass, array $missing): void
    {
        $proofsPath = $this->normalize($this->pathResolver->getProofsPath($targetClass->show_id, $targetClass->name));
        $webPath = $this->normalize($this->pathResolver->getWebImagesPath($targetClass->show_id, $targetClass->name));
        $highresPath = $this->normalize($this->pathResolver->getHighresImagesPath($targetClass->show_id, $targetClass->name));

        if ($missing['proofs']) {
            GenerateThumbnails::dispatch($newPhoto->id, $proofsPath)->onQueue('thumbnails');
        }
        if ($missing['web']) {
            GenerateWebImage::dispatch($newPhoto->id, $webPath)->onQueue('thumbnails');
        }
        if ($missing['highres']) {
            GenerateHighresImage::dispatch($newPhoto->id, $highresPath)->onQueue('thumbnails');
        }
    }

    private function showClassParts(string $showClassId): array
    {
        $parts = explode('_', $showClassId, 2);
        if (count($parts) !== 2) {
            throw new RuntimeException("Invalid show_class_id: {$showClassId}");
        }

        return $parts;
    }

    private function normalize(string $path): string
    {
        return $this->pathResolver->normalizePath($path);
    }

    private function absolutePath(string $relativePath): string
    {
        return rtrim(config('proofgen.fullsize_home_dir'), '/').'/'.ltrim($relativePath, '/');
    }
}
