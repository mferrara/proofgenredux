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

                    // Move the existing row so its unique content hash keeps one owner.
                    $metadata = $photo->metadata;
                    $newPhoto = $photo;
                    $newPhoto->id = $newPhotoId;
                    $newPhoto->show_class_id = $targetClassId;
                    $newPhoto->unsetRelation('showClass');
                    if ($archiveMetadata) {
                        $newPhoto->archive_path = $archiveMetadata['archive_path'];
                        $newPhoto->archive_sha1 = $archiveMetadata['archive_sha1'];
                        $newPhoto->archive_size = $archiveMetadata['archive_size'];
                        $newPhoto->archived_at = $archiveMetadata['archived_at'];
                    }

                    // Use saveQuietly to avoid triggering model events
                    $newPhoto->saveQuietly();

                    // Update metadata
                    if ($metadata) {
                        $metadata->update(['photo_id' => $newPhotoId]);
                    }

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

        $sourceClassModel = $photo->showClass;
        if (! $sourceClassModel) {
            throw new RuntimeException('Source class not found for photo '.$photo->id);
        }
        $sourceShow = $sourceClassModel->show_id;
        $sourceClass = $sourceClassModel->name;
        $targetShow = $targetClass->show_id;
        $targetClassName = $targetClass->name;
        $proofNumber = $photo->proof_number;
        $extension = $photo->file_type;

        $oldOriginal = $this->normalize($this->pathResolver->getOriginalFilePath($sourceShow, $sourceClass, $proofNumber.'.'.$extension));
        $newOriginal = $this->normalize($this->pathResolver->getOriginalFilePath($targetShow, $targetClassName, $proofNumber.'.'.$extension));

        if (! $disk->exists($oldOriginal)) {
            throw new RuntimeException("Original file missing for photo {$photo->id}: {$oldOriginal}");
        }

        // Never overwrite an existing destination original. The bytes already
        // there may be the only copy of another image, so refuse the whole move
        // before touching any source, archive, or DB state.
        if ($disk->exists($newOriginal)) {
            throw new RuntimeException(
                "Cannot move photo {$photo->id}: destination original already exists at {$newOriginal}. "
                .'Refusing to overwrite the existing file; resolve the conflict manually.'
            );
        }

        if (! $disk->move($oldOriginal, $newOriginal)) {
            throw new RuntimeException("Original move failed for photo {$photo->id}: {$oldOriginal} -> {$newOriginal}");
        }

        if (! $disk->exists($newOriginal)) {
            throw new RuntimeException("Original move failed for photo {$photo->id}: {$oldOriginal} -> {$newOriginal}");
        }

        $missingDerivatives = [
            'proofs' => false,
            'web' => false,
            'highres' => false,
        ];

        // Proofs: move every expected thumbnail that exists; if all are missing
        // and proofs were previously generated, queue regen. Derivatives are
        // always encoded as .jpg regardless of the original file_type ("jpeg").
        $proofsMovedCount = 0;
        foreach (config('proofgen.thumbnails') as $config) {
            $suffix = (string) ($config['suffix'] ?? '');
            $oldProof = $this->normalize($this->pathResolver->getProofThumbnailPath($sourceShow, $sourceClass, $proofNumber.'.jpg', $suffix));
            $newProof = $this->normalize($this->pathResolver->getProofThumbnailPath($targetShow, $targetClassName, $proofNumber.'.jpg', $suffix));

            if ($disk->exists($oldProof)) {
                $disk->move($oldProof, $newProof);
                $proofsMovedCount++;
            }
        }
        if ($proofsMovedCount === 0 && $photo->proofs_generated_at !== null) {
            $missingDerivatives['proofs'] = true;
        }

        // Web image
        $webSuffix = (string) config('proofgen.web_images.suffix', '_web');
        $oldWeb = $this->normalize($this->pathResolver->getWebImagePath($sourceShow, $sourceClass, $proofNumber.'.jpg', $webSuffix));
        $newWeb = $this->normalize($this->pathResolver->getWebImagePath($targetShow, $targetClassName, $proofNumber.'.jpg', $webSuffix));
        if ($disk->exists($oldWeb)) {
            $disk->move($oldWeb, $newWeb);
        } elseif ($photo->web_image_generated_at !== null) {
            $missingDerivatives['web'] = true;
        }

        // Highres image
        $highresSuffix = (string) config('proofgen.highres_images.suffix', '_highres');
        $oldHighres = $this->normalize($this->pathResolver->getHighresImagePath($sourceShow, $sourceClass, $proofNumber.'.jpg', $highresSuffix));
        $newHighres = $this->normalize($this->pathResolver->getHighresImagePath($targetShow, $targetClassName, $proofNumber.'.jpg', $highresSuffix));
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
            GenerateWebImage::dispatch($newPhoto->id, $webPath)->onQueue('generate-web');
        }
        if ($missing['highres']) {
            GenerateHighresImage::dispatch($newPhoto->id, $highresPath)->onQueue('generate-highres');
        }
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
