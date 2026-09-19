<?php

namespace App\Services;

use App\Jobs\Photo\GenerateHighresImage;
use App\Jobs\Photo\GenerateThumbnails;
use App\Jobs\Photo\GenerateWebImage;
use App\Jobs\ShowClass\DeliverClassOutputs;
use App\Models\Photo;
use App\Models\Show;
use App\Models\ShowClass;
use App\Proofgen\Image;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

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

        // Importing renames and moves the source file. Without a class record the
        // photo would be moved and numbered, then never proofed or listed anywhere.
        if (! Show::find($show)?->ensureClass($class)) {
            throw new Exception("Cannot import {$imagePath}: no class record for {$show}/{$class} and one cannot be created (unknown show, or a folder name the website rejects).");
        }

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
            $photo = $this->handleIdempotentRetry($plan, $show, $class, $debug);
            if ($dispatchJobs) {
                $this->queueDerivatives($photo, missingOnly: true);
            }

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
        $finalProofNumber = $proofNumberOverride ?? $plan->intendedProofNumber;
        $showModel = null;

        if ($finalProofNumber === null) {
            $showModel = Show::find($show);
            $finalProofNumber = $showModel?->getNextProofNumber();
        }

        if ($finalProofNumber === null) {
            throw new Exception("Unable to determine proof number for import: {$imagePath}");
        }

        try {
            return $this->runImport($imagePath, $finalProofNumber, $debug, $dispatchJobs, plan: $plan);
        } catch (Throwable $exception) {
            // A number taken from the pool for an import that then failed would
            // otherwise be lost: a gap in the sequence, and the retried photo
            // numbered out of shooting order.
            $showModel?->returnProofNumber($finalProofNumber);

            throw $exception;
        }
    }

    private function runImport(string $imagePath, string $finalProofNumber, bool $debug, bool $dispatchJobs, ?PhotoImportPlan $plan): array
    {
        $imageObj = new Image($imagePath, $this->pathResolver);
        $photo = $imageObj->processImage($finalProofNumber, $debug);

        $show_class = ShowClass::find($imageObj->show.'_'.$imageObj->class);
        $proofDestPath = $show_class->proofs_path;
        $webImagesPath = $show_class->web_images_path;
        $highresImagesPath = $show_class->highres_images_path;

        if ($dispatchJobs) {
            $this->queueDerivatives($photo);
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

    private function queueDerivatives(Photo $photo, bool $missingOnly = false): void
    {
        if (! $missingOnly || $photo->proofs_generated_at === null) {
            GenerateThumbnails::dispatch($photo->id, $photo->proofs_path)->onQueue('thumbnails');
        }
        if ((! $missingOnly || $photo->web_image_generated_at === null) && config('proofgen.generate_web_images.enabled', true)) {
            GenerateWebImage::dispatch($photo->id, $photo->showClass->web_images_path)->onQueue('generate-web');
        }
        if ((! $missingOnly || $photo->highres_image_generated_at === null) && config('proofgen.generate_highres_images.enabled', true)) {
            GenerateHighresImage::dispatch($photo->id, $photo->showClass->highres_images_path)->onQueue('generate-highres');
        }
    }

    /**
     * Same bytes already represented in this show/class: refresh archive metadata
     * if drifted, ensure original_filename is set, restore a missing original from
     * the verified incoming bytes, then bury the retry source. Identity and proof
     * number are preserved — no new record and no new number.
     */
    private function handleIdempotentRetry(PhotoImportPlan $plan, string $show, string $class, bool $debug): Photo
    {
        $photo = $plan->existingByContent;
        $storage = Storage::disk('fullsize');
        $originalRelativePath = $this->pathResolver->normalizePath(
            $this->pathResolver->getOriginalFilePath($show, $class, $photo->proof_number.'.'.$photo->file_type)
        );

        // Restore a missing original from the incoming bytes. A present original
        // is never overwritten: the incoming file is a retry of the same content,
        // not a replacement.
        if (! $storage->exists($originalRelativePath)) {
            $incoming = $storage->get($plan->sourcePath);
            $recordedSha1 = (string) $photo->sha1;

            if ($incoming === false || $recordedSha1 === '' || sha1($incoming) !== $recordedSha1) {
                throw new RuntimeException(
                    'Cannot restore missing original for '.$photo->id.
                    ': incoming source no longer matches the recorded file identity.'
                );
            }

            $directory = dirname($originalRelativePath);
            SafeDirectory::ensure($storage, $directory);

            $storage->put($originalRelativePath, $incoming);
            $written = $storage->get($originalRelativePath);
            if ($written === false || sha1($written) !== $recordedSha1 || strlen($written) !== strlen($incoming)) {
                throw new RuntimeException('Original restoration verification failed; '.$originalRelativePath);
            }

            unset($incoming, $written);

            if ($debug) {
                Log::debug('Restored missing original from idempotent retry; '.$originalRelativePath);
            }
        }

        // A worker can stop after the photo row is inserted but before the
        // created hook finishes. Repair metadata before retiring the retry source.
        if (! config('testing.skip_file_operations') && ! $photo->metadata()->exists()) {
            $photo->createMetadataRecord();
        }

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

        // Never bury a source that IS the original itself — that would destroy the
        // only copy we just verified/restored.
        $sourcePath = $this->pathResolver->normalizePath($plan->sourcePath);
        if ($sourcePath !== $originalRelativePath && $storage->exists($sourcePath)) {
            app(SafeFileMover::class)->bury(
                disk: 'fullsize',
                path: $sourcePath,
                reason: SafeFileMover::REASON_POST_IMPORT_SOURCE,
                context: [
                    'sha1' => $plan->sha1,
                    'size' => $plan->size,
                    'photo_id' => $photo->id,
                    'original_filename' => $plan->originalFilename,
                    'idempotent_retry' => true,
                ],
            );
        }

        if ($debug) {
            Log::debug('Idempotent re-import; preserved source identity for photo '.$photo->id.'.');
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
            throw new Exception("Photo not found with ID: {$photo_id}");
        }
        $photoPath = $photo->relative_path;
        $photoPath = $this->pathResolver->normalizePath($photoPath);
        $proofsDestinationPath = $this->pathResolver->normalizePath($proofsDestinationPath);

        $result = Image::createThumbnails($photoPath, $proofsDestinationPath);

        $photo->proofs_generated_at = now();
        $photo->save();

        if ($checkForUpload) {
            // Check class, if no more images pending proofs we'll queue up delivery
            $pendingProofs = $photo->showClass->photos()->whereNull('proofs_generated_at')->count();

            if ($pendingProofs === 0) {
                $this->maybeQueueClassDelivery($photo, 'proofs');
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
            // Check class, if no more images pending web images we'll queue up delivery
            $pendingWebImages = $photo->showClass->photos()->whereNull('web_image_generated_at')->count();

            if ($pendingWebImages === 0) {
                $this->maybeQueueClassDelivery($photo, 'web');
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
            // Check class, if no more images pending highres images we'll queue up delivery
            $pendingHighresImages = $photo->showClass->photos()->whereNull('highres_image_generated_at')->count();

            if ($pendingHighresImages === 0) {
                $this->maybeQueueClassDelivery($photo, 'highres');
            }
        }

        return $result;
    }

    /** Queue completed class outputs; explicit uploads bypass this switch. */
    /**
     * Each kind announces its own completion and is delivered on its own queue,
     * so a class's proofs go up as soon as they exist and never wait for (or
     * behind) its web and highres images.
     */
    private function maybeQueueClassDelivery(Photo $photo, string $kind): void
    {
        if (app(AutomaticUploadSettings::class)->enabled()) {
            DeliverClassOutputs::dispatch($photo->show_class_id, true, [$kind]);
        }
    }
}
