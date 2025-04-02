<?php

namespace App\Proofgen;

use App\Jobs\Photo\GenerateThumbnails;
use App\Jobs\Photo\GenerateWebImage;
use App\Jobs\Photo\ImportPhoto;
use App\Services\PathResolver;
use Illuminate\Support\Facades\Storage;

class ShowClass
{
    protected string $show_folder = '';
    protected string $class_folder = '';
    protected string $fullsize_base_path = '';
    protected string $archive_base_path = '';

    protected string $fullsize_path = '';
    protected string $originals_path = '';
    protected string $proofs_path = '';
    protected string $remote_proofs_path = '';
    protected string $web_images_path = '';
    protected string $remote_web_images_path = '';
    protected PathResolver $pathResolver;

    public function __construct(string $show_folder, string $class_folder, ?PathResolver $pathResolver = null)
    {
        $this->show_folder = $show_folder;
        $this->class_folder = $class_folder;
        $this->fullsize_base_path = config('proofgen.fullsize_home_dir');
        $this->archive_base_path = config('proofgen.archive_home_dir');

        // Use dependency injection or create a new PathResolver instance
        $this->pathResolver = $pathResolver ?? app(PathResolver::class);

        // Use PathResolver for local paths
        $this->fullsize_path = $this->pathResolver->getFullsizePath($show_folder, $class_folder);
        $this->originals_path = $this->pathResolver->getOriginalsPath($show_folder, $class_folder);
        $this->proofs_path = $this->pathResolver->getProofsPath($show_folder, $class_folder);
        $this->web_images_path = $this->pathResolver->getWebImagesPath($show_folder, $class_folder);

        // Use PathResolver for remote paths
        $this->remote_proofs_path = $this->pathResolver->getRemoteProofsPath($show_folder, $class_folder);
        $this->remote_web_images_path = $this->pathResolver->getRemoteWebImagesPath($show_folder, $class_folder);
    }

    public function rsyncProofsCommand($dry_run = false): string
    {
        $local_full_path = $this->pathResolver->getAbsolutePath($this->proofs_path, $this->fullsize_base_path) . '/';
        $dry_run = $dry_run === true ? '--dry-run' : '';

        return 'rsync -avz --delete '.$dry_run.' -e "ssh -i '.config('proofgen.sftp.private_key').'" '.
            $local_full_path.' forge@'.config('proofgen.sftp.host').':'.config('proofgen.sftp.path').
            $this->remote_proofs_path;
    }

    public function rsyncWebImagesCommand($dry_run = false): string
    {
        $local_full_path = $this->pathResolver->getAbsolutePath($this->web_images_path, $this->fullsize_base_path) . '/';
        $dry_run = $dry_run === true ? '--dry-run' : '';

        return 'rsync -avz --delete '.$dry_run.' -e "ssh -i '.config('proofgen.sftp.private_key').'" '.
            $local_full_path.' forge@'.config('proofgen.sftp.host').':'.config('proofgen.sftp.web_images_path').
            $this->remote_web_images_path;
    }

    /**
     * Get the images that have been imported/are inside the originals directory
     *
     * @return array
     */
    public function getImportedImages(): array
    {
        $contents = Utility::getContentsOfPath($this->originals_path, false);

        $images = [];
        if(isset($contents['images']))
            $images = $contents['images'];

        return $images;
    }

    /**
     * Get the images that are in the originals directory but not in the proofs directory
     *
     * @return array
     */
    public function getImagesPendingProofing(): array
    {
        // Get contents of the originals directory and compare to contents of the proofs directory
        // The files that are in the originals directory but not in the proofs directory those that need to be proofed
        $originals = Utility::getContentsOfPath($this->originals_path, false);
        $proofs = Utility::getContentsOfPath($this->proofs_path, false);

        $images = [];
        $original_images = [];
        $proofs_images = [];
        if(isset($originals['images'])) {
            $original_images = $originals['images'];
        }
        if(isset($proofs['images'])) {
            $proofs_images_temp = $proofs['images'];
            foreach($proofs_images_temp as $temp_proof) {
                if(str_contains($temp_proof->path(), '_std')) {
                    $temp_proof_filename = explode('/', $temp_proof->path());
                    $temp_proof_filename = array_pop($temp_proof_filename);
                    $proofs_images[] = $temp_proof_filename;
                }
            }
        }

        foreach($original_images as $original_image) {
            $original_image_filename = explode('/', $original_image->path());
            $original_image_filename = array_pop($original_image_filename);
            // Fix for issues where the original has a capitalised extension
            $original_image_filename = str_replace('.JPG', '.jpg', $original_image_filename);
            $proof_name_to_check = str_replace('.jpg', '_std.jpg', $original_image_filename);
            if(!in_array($proof_name_to_check, $proofs_images)) {
                $images[] = $original_image;
            }
        }

        return $images;
    }

    /**
     * Get the images that are in the originals directory but not in the web images directory
     *
     * @return array
     */
    public function getImagesPendingWeb(): array
    {
        // Get contents of the originals directory and compare to contents of the proofs directory
        // The files that are in the originals directory but not in the proofs directory those that need to
        // have web images generated
        $originals = Utility::getContentsOfPath($this->originals_path, false);
        $web_images_array = Utility::getContentsOfPath($this->web_images_path, false);

        $images = [];
        $original_images = [];
        $web_images = [];
        if(isset($originals['images'])) {
            $original_images = $originals['images'];
        }
        if(isset($web_images_array['images'])) {
            $web_images_temp = $web_images_array['images'];
            foreach($web_images_temp as $temp_web_image) {
                if(str_contains($temp_web_image->path(), '_web')) {
                    $temp_web_filename = explode('/', $temp_web_image->path());
                    $temp_web_filename = array_pop($temp_web_filename);
                    $web_images[] = $temp_web_filename;
                }
            }
        }

        foreach($original_images as $original_image) {
            $original_image_filename = explode('/', $original_image->path());
            $original_image_filename = array_pop($original_image_filename);
            // Fix for issues where the original has a capitalised extension
            $original_image_filename = str_replace('.JPG', '.jpg', $original_image_filename);
            $web_name_to_check = str_replace('.jpg', '_web.jpg', $original_image_filename);
            if(!in_array($web_name_to_check, $web_images)) {
                $images[] = $original_image;
            }
        }

        return $images;
    }

    /**
     * Get the images that are in the fullsize directory but not in the originals directory
     *
     * @return array
     */
    public function getImagesPendingProcessing(): array
    {
        $contents = Utility::getContentsOfPath($this->fullsize_path, false);

        $images = [];
        if(isset($contents['images']))
            $images = $contents['images'];

        return $images;
    }

    /**
     * Regenerate the proofs for all images in the originals directory
     *
     * @return int
     */
    public function regenerateProofs(): int
    {
        $images = $this->getImportedImages();

        $proofed = 0;
        if($images) {
            foreach($images as $image) {
                GenerateThumbnails::dispatch($this->show_folder.'_'.$this->class_folder, $this->proofs_path)->onQueue('thumbnails');
                $proofed++;
            }
        }

        return $proofed;
    }

    /**
     * Regenerate the web images for all images in the originals directory
     *
     * @return int
     */
    public function regenerateWebImages(): int
    {
        $images = $this->getImportedImages();

        $proofed = 0;
        if($images) {
            foreach($images as $image) {
                $image_path = $image->path();
                GenerateWebImage::dispatch($image_path, $this->web_images_path)->onQueue('thumbnails');
                $proofed++;
            }
        }

        return $proofed;
    }

    /**
     * Process all images that are in the fullsize directory but not in the originals directory
     *
     * @return int
     */
    public function processPendingImages(): int
    {
        $images = $this->getImagesPendingProcessing();

        $processed = 0;
        if ($images) {
            foreach ($images as $image) {
                ImportPhoto::dispatch($image->path(), $this->getNextProofNumber())->onQueue('processing');
                $processed++;
            }
        }

        return $processed;
    }

    /**
     * Process a single image, which includes 'importing' the image;
     *  - Copy from fullsize to originals
     *  - Rename to proof number (optional, based on config)
     *  - Copy to archive/backup location (optional, based on config)
     *  - Confirm all copies
     *  - Delete from fullsize
     *
     * @param string $image_path
     * @return void
     * @throws \Exception
     */
    public function processImage(string $image_path): void
    {
        $image_obj = new Image($image_path, $this->pathResolver);
        $proof_numbers = Utility::generateProofNumbers($this->show_folder, 1);
        $image_obj->processImage(array_shift($proof_numbers), false);
    }

    /**
     * Get the next available proof number from the redis list
     *
     * @return string
     */
    public function getNextProofNumber(): string
    {
        $redis_key = 'available_proof_numbers_' . $this->show_folder;

        $redis_client = \Illuminate\Support\Facades\Redis::client();
        // Do we have a redis list with the $redis_key or, if we have one, but it's empty...
        if (!$redis_client->exists($redis_key) || $redis_client->llen($redis_key) === 0) {
            // Generate the proof numbers
            $proof_numbers = Utility::generateProofNumbers($this->show_folder, 10000);
            // Add the proof numbers to the redis list
            foreach($proof_numbers as $available_proof_number) {
                $redis_client->rpush($redis_key, $available_proof_number);
            }
        }
        $proof_number = $redis_client->lpop($redis_key);

        return $proof_number;
    }
}
