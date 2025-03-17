<?php

namespace App\Services;

use App\Jobs\Photo\GenerateThumbnails;
use App\Jobs\Photo\GenerateWebImage;
use App\Jobs\ShowClass\UploadProofs;
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
     * @param bool $dispatchJobs Whether to dispatch the thumbnail and web image jobs (true for production, false for testing)
     * @return array Returns [fullsizeImagePath, proofDestPath, webImagesPath] for further processing
     * @throws Exception
     */
    public function processPhoto(string $imagePath, string $proofNumber, bool $debug = false, bool $dispatchJobs = true): array
    {
        // Normalize the path and remove leading slash if present
        $imagePath = $this->pathResolver->normalizePath($imagePath);
        
        $imageObj = new Image($imagePath, $this->pathResolver);
        $fullsizeImagePath = $imageObj->processImage($proofNumber, $debug);

        // Use PathResolver to get standardized paths
        $proofDestPath = $this->pathResolver->getProofsPath($imageObj->show, $imageObj->class);
        $webImagesPath = $this->pathResolver->getWebImagesPath($imageObj->show, $imageObj->class);

        // Dispatch jobs to generate thumbnails and web images if requested
        if ($dispatchJobs) {
            GenerateWebImage::dispatch($fullsizeImagePath, $webImagesPath)->onQueue('thumbnails');
            GenerateThumbnails::dispatch($fullsizeImagePath, $proofDestPath)->onQueue('thumbnails');
        }

        return [
            'fullsizeImagePath' => $fullsizeImagePath,
            'proofDestPath' => $proofDestPath,
            'webImagesPath' => $webImagesPath,
        ];
    }

    /**
     * Generate thumbnails for a photo and optionally check if upload job should be dispatched
     *
     * @param string $photoPath The path to the photo
     * @param string $proofsDestinationPath The path to store proofs
     * @param bool $checkForUpload Whether to check if all images are processed and queue upload job
     * @return string The image filename that was processed
     */
    public function generateThumbnails(string $photoPath, string $proofsDestinationPath, bool $checkForUpload = true): string
    {
        // Normalize paths to ensure consistency
        $photoPath = $this->pathResolver->normalizePath($photoPath);
        $proofsDestinationPath = $this->pathResolver->normalizePath($proofsDestinationPath);
        
        $result = Image::createThumbnails($photoPath, $proofsDestinationPath);

        if ($checkForUpload) {
            // Check class, if no more images pending proofs we'll queue up the upload job
            $image = new Image($photoPath, $this->pathResolver);
            $showClass = new ShowClass($image->show, $image->class, $this->pathResolver);
            $pendingProofs = $showClass->getImagesPendingProofing();

            if (count($pendingProofs) === 0) {
                UploadProofs::dispatch($image->show, $image->class);
            }
        }

        return $result;
    }

    /**
     * Generate a web-optimized version of a photo
     *
     * @param string $fullSizePath The path to the full-size photo
     * @param string $webDestinationPath The path to store web images
     * @return string The image filename that was processed
     */
    public function generateWebImage(string $fullSizePath, string $webDestinationPath): string
    {
        // Normalize paths to ensure consistency
        $fullSizePath = $this->pathResolver->normalizePath($fullSizePath);
        $webDestinationPath = $this->pathResolver->normalizePath($webDestinationPath);
        
        return Image::createWebImage($fullSizePath, $webDestinationPath);
    }
}
