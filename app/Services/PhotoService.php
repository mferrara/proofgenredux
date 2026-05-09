<?php

namespace App\Services;

use App\Jobs\Photo\GenerateHighresImage;
use App\Jobs\Photo\GenerateThumbnails;
use App\Jobs\Photo\GenerateWebImage;
use App\Jobs\ShowClass\UploadHighresImages;
use App\Jobs\ShowClass\UploadProofs;
use App\Jobs\ShowClass\UploadWebImages;
use App\Models\Photo;
use App\Models\Show;
use App\Proofgen\Image;
use Exception;

class PhotoService
{
    protected PathResolver $pathResolver;

    public function __construct(?PathResolver $pathResolver = null)
    {
        $this->pathResolver = $pathResolver ?? new PathResolver;
    }

    /**
     * Process a photo: resolve identity first, only allocate a proof number when the
     * resolver confirms a genuinely new file, then write archive/original/DB and bury source.
     *
     * Return shape:
     *   ['photo' => Photo|null, 'plan' => PhotoImportPlan|null, 'issue' => PhotoIssue|null,
     *    'proofDestPath' => ?string, 'webImagesPath' => ?string, 'highresImagesPath' => ?string]
     *
     * @param  string  $imagePath  Image path relative to fullsize disk
     * @param  string|null  $proofNumberOverride  Caller-supplied proof number; bypasses Redis allocator
     * @param  bool  $debug  Debug logging
     * @param  bool  $dispatchJobs  Dispatch derivative jobs after import
     * @param  bool  $bypassResolver  Skip the resolver and force the import.
     *                                Requires $proofNumberOverride. Used when an
     *                                operator has already decided how a flagged
     *                                conflict resolves and going through the
     *                                resolver would re-classify the file as the
     *                                same conflict that produced the issue.
     *
     * @throws Exception
     */
    public function processPhoto(string $imagePath, ?string $proofNumberOverride = null, bool $debug = false, bool $dispatchJobs = true, bool $bypassResolver = false): array
    {
        $imagePath = $this->pathResolver->normalizePath($imagePath);

        $parts = explode('/', $imagePath, 3);
        if (count($parts) < 3) {
            throw new Exception("Invalid image path (expected show/class/filename): {$imagePath}");
        }
        [$show, $class] = [$parts[0], $parts[1]];

        if ($bypassResolver) {
            if ($proofNumberOverride === null) {
                throw new Exception('bypassResolver requires an explicit proofNumberOverride.');
            }

            return $this->runImport($imagePath, $proofNumberOverride, $debug, $dispatchJobs, plan: null);
        }

        $plan = app(PhotoImportIdentityResolver::class)->resolve($imagePath, $show, $class);

        if ($plan->requiresReview()) {
            $issue = app(PhotoImportIssueRecorder::class)->record($plan, $show, $class);

            return [
                'photo' => null,
                'plan' => $plan,
                'issue' => $issue,
                'proofDestPath' => null,
                'webImagesPath' => null,
                'highresImagesPath' => null,
            ];
        }

        if ($plan->isIdempotent()) {
            $photo = $this->handleIdempotentRetry($plan, $debug);

            return [
                'photo' => $photo,
                'plan' => $plan,
                'issue' => null,
                'proofDestPath' => null,
                'webImagesPath' => null,
                'highresImagesPath' => null,
            ];
        }

        // Genuinely new file: allocate proof number only now.
        $finalProofNumber = $proofNumberOverride
            ?? $plan->intendedProofNumber
            ?? Show::find($show)?->getNextProofNumber();

        if ($finalProofNumber === null) {
            throw new Exception("Unable to determine proof number for import: {$imagePath}");
        }

        return $this->runImport($imagePath, $finalProofNumber, $debug, $dispatchJobs, plan: $plan);
    }

    private function runImport(string $imagePath, string $finalProofNumber, bool $debug, bool $dispatchJobs, ?PhotoImportPlan $plan): array
    {
        $imageObj = new Image($imagePath, $this->pathResolver);
        $photo = $imageObj->processImage($finalProofNumber, $debug);

        $show_class = \App\Models\ShowClass::find($imageObj->show.'_'.$imageObj->class);
        $proofDestPath = $show_class->proofs_path;
        $webImagesPath = $show_class->web_images_path;
        $highresImagesPath = $show_class->highres_images_path;

        if ($dispatchJobs) {
            GenerateThumbnails::dispatch($photo->id, $proofDestPath)->onQueue('thumbnails');
            GenerateWebImage::dispatch($photo->id, $webImagesPath)->onQueue('thumbnails');
            GenerateHighresImage::dispatch($photo->id, $highresImagesPath)->onQueue('thumbnails');
        }

        return [
            'photo' => $photo,
            'plan' => $plan,
            'issue' => null,
            'proofDestPath' => $proofDestPath,
            'webImagesPath' => $webImagesPath,
            'highresImagesPath' => $highresImagesPath,
        ];
    }

    /**
     * Same image already imported with the same proof number in the same class:
     * refresh archive metadata if drifted, ensure original_filename is set, bury the source.
     */
    private function handleIdempotentRetry(PhotoImportPlan $plan, bool $debug): Photo
    {
        $photo = $plan->existingByContent;

        $archiveService = app(PhotoArchiveService::class);
        if ($archiveService->enabled()) {
            $audit = $archiveService->auditPhoto($photo);
            if (in_array($audit['status'], ['archive_missing', 'metadata_stale', 'archive_mismatched'], true)) {
                $archiveService->repairPhoto($photo);
                $photo->refresh();
            }
        }

        if (empty($photo->original_filename)) {
            $photo->forceFill(['original_filename' => $plan->originalFilename])->save();
        }

        app(SafeFileMover::class)->bury(
            disk: 'fullsize',
            path: $plan->sourcePath,
            reason: SafeFileMover::REASON_POST_IMPORT_SOURCE,
            context: [
                'sha1' => $plan->sha1,
                'size' => $plan->size,
                'photo_id' => $photo->id,
                'original_filename' => $plan->originalFilename,
                'idempotent_retry' => true,
            ],
        );

        if ($debug) {
            Log::debug(
                'Idempotent re-import; buried duplicate source; '.$plan->sourcePath.' (photo '.$photo->id.')'
            );
        }

        return $photo;
    }

    /**
     * Generate thumbnails for a photo and optionally check if upload job should be dispatched
     *
     * @param  string  $photo_id  The id of the photo record
     * @param  string  $proofsDestinationPath  The path to store proofs
     * @param  bool  $checkForUpload  Whether to check if all images are processed and queue upload job
     * @return string The image filename that was processed
     */
    public function generateThumbnails(string $photo_id, string $proofsDestinationPath, bool $checkForUpload = true): string
    {
        // Normalize paths to ensure consistency
        $photo = Photo::find($photo_id);
        if (! $photo) {
            throw new \Exception("Photo not found with ID: {$photo_id}");
        }
        $photoPath = $photo->relative_path;
        $photoPath = $this->pathResolver->normalizePath($photoPath);
        $proofsDestinationPath = $this->pathResolver->normalizePath($proofsDestinationPath);

        $result = Image::createThumbnails($photoPath, $proofsDestinationPath);

        $photo->proofs_generated_at = now();
        $photo->save();

        if ($checkForUpload) {
            // Check class, if no more images pending proofs we'll queue up the upload job
            $pendingProofs = $photo->showClass->photos()->whereNull('proofs_generated_at')->count();

            if ($pendingProofs === 0) {
                UploadProofs::dispatch($photo->showClass->show->id, $photo->showClass->name);
            }
        }

        return $result;
    }

    /**
     * Generate a web-optimized version of a photo
     *
     * @param  string  $photo_id  The id of the photo record
     * @param  string  $webDestinationPath  The path to store web images
     * @return string The full path to the output file
     */
    public function generateWebImage(string $photo_id, string $webDestinationPath, bool $checkForUpload = true): string
    {
        // Normalize paths to ensure consistency
        /** @var Photo $photo */
        $photo = Photo::find($photo_id);
        if (! $photo) {
            throw new Exception('Photo not found for id: '.$photo_id.' on PhotoService::generateWebImage');
        }
        $photoPath = $photo->relative_path;
        $photoPath = $this->pathResolver->normalizePath($photoPath);
        $webDestinationPath = $this->pathResolver->normalizePath($webDestinationPath);

        $result = Image::createWebImage($photoPath, $webDestinationPath);

        $photo->web_image_generated_at = now();
        $photo->save();

        if ($checkForUpload) {
            // Check class, if no more images pending web images we'll queue up the upload job
            $pendingWebImages = $photo->showClass->photos()->whereNull('web_image_generated_at')->count();

            if ($pendingWebImages === 0) {
                UploadWebImages::dispatch($photo->showClass->show->id, $photo->showClass->name);
            }
        }

        return $result;
    }

    /**
     * Generate a high-resolution version of a photo
     *
     * @param  string  $photo_id  The id of the photo record
     * @param  string  $highresDestinationPath  The path to store highres images
     * @return string The full path to the output file
     */
    public function generateHighresImage(string $photo_id, string $highresDestinationPath, bool $checkForUpload = true): string
    {
        // Normalize paths to ensure consistency
        /** @var Photo $photo */
        $photo = Photo::find($photo_id);
        if (! $photo) {
            throw new Exception('Photo not found for id: '.$photo_id.' on PhotoService::generateHighresImage');
        }
        $photoPath = $photo->relative_path;
        $photoPath = $this->pathResolver->normalizePath($photoPath);
        $highresDestinationPath = $this->pathResolver->normalizePath($highresDestinationPath);

        $result = Image::createHighresImage($photoPath, $highresDestinationPath);

        $photo->highres_image_generated_at = now();
        $photo->save();

        if ($checkForUpload) {
            // Check class, if no more images pending highres images we'll queue up the upload job
            $pendingHighresImages = $photo->showClass->photos()->whereNull('highres_image_generated_at')->count();

            if ($pendingHighresImages === 0) {
                UploadHighresImages::dispatch($photo->showClass->show->id, $photo->showClass->name);
            }
        }

        return $result;
    }
}
