<?php

namespace App\Services;

use App\Jobs\Photo\GenerateThumbnails;
use App\Jobs\Photo\GenerateWebImage;
use App\Jobs\ShowClass\UploadProofs;
use App\Jobs\ShowClass\UploadWebImages;
use App\Models\Photo;
use App\Proofgen\Image;
use App\Proofgen\ShowClass;
use Exception;

class PhotoService
{
    protected PathResolver $pathResolver;

    public function __construct(PathResolver $pathResolver = null)
    {
        $this->pathResolver = $pathResolver ?? new PathResolver();
    }

    /**
     * Process a photo by renaming, archiving, and optionally dispatching thumbnail/web image jobs
     *
     * @param string $imagePath The path to the image to process
     * @param string $proofNumber The proof number to assign
     * @param bool $debug Whether to enable debug logging
     * @return array Returns [fullsizeImagePath, proofDestPath, webImagesPath] for further processing
     * @throws Exception
     */
    public function processPhoto(string $imagePath, string $proofNumber, bool $debug = false): array
    {
        // Normalize the path and remove leading slash if present
        $imagePath = $this->pathResolver->normalizePath($imagePath);

        $imageObj = new Image($imagePath, $this->pathResolver);
        $photo = $imageObj->processImage($proofNumber, $debug);

        $show_class = \App\Models\ShowClass::find($imageObj->show.'_'.$imageObj->class);
        $proofDestPath = $show_class->proofs_path;
        $webImagesPath = $show_class->web_images_path;

        // Dispatch jobs for generating thumbnails and web images
        // \Log::debug('Queueing GenerateThumbnails job for photo_id: '.$photo->id);
        GenerateThumbnails::dispatch($photo->id, $proofDestPath)->onQueue('thumbnails');
        // \Log::debug('Queueing GenerateWebImage job for photo_id: '.$photo->id);
        GenerateWebImage::dispatch($photo->id, $webImagesPath)->onQueue('thumbnails');

        return [
            'photo' => $photo,
            'proofDestPath' => $proofDestPath,
            'webImagesPath' => $webImagesPath,
        ];
    }

    /**
     * Generate thumbnails for a photo and optionally check if upload job should be dispatched
     *
     * @param string $photo_id The id of the photo record
     * @param string $proofsDestinationPath The path to store proofs
     * @param bool $checkForUpload Whether to check if all images are processed and queue upload job
     * @return string The image filename that was processed
     */
    public function generateThumbnails(string $photo_id, string $proofsDestinationPath, bool $checkForUpload = true): string
    {
        // Normalize paths to ensure consistency
        $photo = Photo::find($photo_id);
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
                UploadProofs::dispatch($photo->showClass->show->name, $photo->showClass->name);
            }
        }

        return $result;
    }

    /**
     * Generate a web-optimized version of a photo
     *
     * @param string $photo_id The id of the photo record
     * @param string $webDestinationPath The path to store web images
     * @return string The full path to the output file
     */
    public function generateWebImage(string $photo_id, string $webDestinationPath, bool $checkForUpload = true): string
    {
        // Normalize paths to ensure consistency
        /** @var Photo $photo */
        $photo = Photo::find($photo_id);
        if( ! $photo) {
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
                UploadWebImages::dispatch($photo->showClass->show->name, $photo->showClass->name);
            }
        }

        return $result;
    }
}
