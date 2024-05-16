<?php

namespace App\Proofgen;

use App\Jobs\Photo\GenerateThumbnails;
use App\Jobs\Photo\GenerateWebImage;
use App\Jobs\Photo\ImportPhoto;
use Illuminate\Support\Facades\Log;

class ShowClass
{
    protected string $show_folder = '';
    protected string $class_folder = '';
    protected string $fullsize_base_path = '';
    protected string $archive_base_path = '';
    protected string $proofs_path = '';
    protected string $remote_proofs_path = '';
    protected string $web_images_path = '';
    protected string $remote_web_images_path = '';

    public function __construct(string $show_folder, string $class_folder)
    {
        $this->show_folder = $show_folder;
        $this->class_folder = $class_folder;
        $this->fullsize_base_path = config('proofgen.fullsize_home_dir');
        $this->archive_base_path = config('proofgen.archive_home_dir');
        $this->proofs_path = '/proofs/'.$this->show_folder . '/' . $this->class_folder;
        $this->remote_proofs_path = '/'.$this->show_folder.'/'.$this->class_folder;
        $this->web_images_path = '/web_images/'.$this->show_folder . '/' . $this->class_folder;
        $this->remote_web_images_path = '/'.$this->show_folder.'/'.$this->class_folder;
    }

    public function rsyncProofsCommand($dry_run = false): string
    {
        $local_full_path = $this->fullsize_base_path.$this->proofs_path.'/';
        $dry_run = $dry_run === true ? '--dry-run' : '';

        return 'rsync -avz --delete '.$dry_run.' -e "ssh -i '.config('proofgen.sftp.private_key').'" '.$local_full_path.' forge@'.config('proofgen.sftp.host').':'.config('proofgen.sftp.path').$this->remote_proofs_path;
    }

    public function rsyncWebImagesCommand($dry_run = false): string
    {
        $local_full_path = $this->fullsize_base_path.$this->web_images_path.'/';
        $dry_run = $dry_run === true ? '--dry-run' : '';

        return 'rsync -avz --delete '.$dry_run.' -e "ssh -i '.config('proofgen.sftp.private_key').'" '.$local_full_path.' forge@'.config('proofgen.sftp.host').':'.config('proofgen.sftp.web_images_path').$this->remote_web_images_path;
    }

    public function uploadPendingProofs(): array
    {
        $command = $this->rsyncProofsCommand();
        exec($command, $output, $returnCode);

        $uploaded_proofs = [];

        foreach ($output as $line) {
            $line = trim($line);

            if(str_starts_with(strtolower($line), '.')
                || str_ends_with(strtolower($line), '/')
                || str_starts_with(strtolower($line), 'deleting')
            )
                continue;

            if (!empty($line) && str_starts_with(strtolower($line), strtolower($this->show_folder))) {
                $parts = explode('/', $line);
                $fileName = end($parts);

                if (!empty($fileName) && strpos($fileName, '.') !== false) {
                    $uploaded_proofs[] = $this->proofs_path.'/'.$fileName;
                }
            }
        }

        return $uploaded_proofs;
    }

    public function pendingProofUploads(): array
    {
        $remote_filesystem = Utility::remoteFilesystem(config('proofgen.sftp.path'));
        if( ! $remote_filesystem->has($this->remote_proofs_path)) {
            $remote_filesystem->createDirectory($this->remote_proofs_path);
        }

        $command = $this->rsyncProofsCommand(true);
        exec($command, $output, $returnCode);

        $pending_proofs = [];

        foreach ($output as $line) {
            $line = trim($line);

            if(str_starts_with(strtolower($line), '.')
                || str_ends_with(strtolower($line), '/')
                || str_starts_with(strtolower($line), 'deleting')
            )
                continue;

            if (!empty($line) && str_starts_with(strtolower($line), strtolower($this->show_folder))) {
                $parts = explode('/', $line);
                $fileName = end($parts);

                if (!empty($fileName) && strpos($fileName, '.') !== false) {
                    $pending_proofs[] = $this->proofs_path.'/'.$fileName;
                }
            }
        }

        return $pending_proofs;
    }

    public function pendingWebImageUploads(): array
    {
        $remote_filesystem = Utility::remoteFilesystem(config('proofgen.sftp.web_images_path'));
        if( ! $remote_filesystem->has($this->remote_web_images_path)) {
            $remote_filesystem->createDirectory($this->remote_web_images_path);
        }

        $command = $this->rsyncWebImagesCommand(true);
        exec($command, $output, $returnCode);

        $pending_web_images = [];

        foreach ($output as $line) {
            $line = trim($line);

            if(str_starts_with(strtolower($line), '.')
                || str_ends_with(strtolower($line), '/')
                || str_starts_with(strtolower($line), 'deleting')
            )
                continue;

            if (!empty($line) && str_starts_with(strtolower($line), strtolower($this->show_folder))) {
                $parts = explode('/', $line);
                $fileName = end($parts);

                if (!empty($fileName) && strpos($fileName, '.') !== false) {
                    $pending_web_images[] = $this->web_images_path.'/'.$fileName;
                }
            }
        }

        return $pending_web_images;
    }

    public function uploadPendingWebImages(): array
    {
        // Confirm show directory exists in web_images
        $remote_filesystem = Utility::remoteFilesystem(config('proofgen.sftp.web_images_path'));
        if( ! $remote_filesystem->has($this->remote_web_images_path)) {
            Log::debug('Creating web_images directory: '.$this->remote_web_images_path);
            $remote_filesystem->createDirectory($this->remote_web_images_path);
        }
        $command = $this->rsyncWebImagesCommand();
        exec($command, $output, $returnCode);

        $uploaded_web_images = [];

        foreach ($output as $line) {
            $line = trim($line);

            if(str_starts_with(strtolower($line), '.')
                || str_ends_with(strtolower($line), '/')
                || str_starts_with(strtolower($line), 'deleting')
            )
                continue;

            if (!empty($line) && str_starts_with(strtolower($line), strtolower($this->show_folder))) {
                $parts = explode('/', $line);
                $fileName = end($parts);

                if (!empty($fileName) && strpos($fileName, '.') !== false) {
                    $uploaded_web_images[] = $this->web_images_path.'/'.$fileName;
                }
            }
        }

        return $uploaded_web_images;
    }

    public function getImportedImages(): array
    {
        $contents = Utility::getContentsOfPath('/'.$this->show_folder.'/'.$this->class_folder.'/originals', false);

        $images = [];
        if(isset($contents['images']))
            $images = $contents['images'];

        return $images;
    }

    public function getImagesPendingProofing(): array
    {
        // Get contents of the originals directory and compare to contents of the proofs directory
        $originals = Utility::getContentsOfPath('/'.$this->show_folder.'/'.$this->class_folder.'/originals', false);
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

    public function getImagesPendingProcessing(): array
    {
        $contents = Utility::getContentsOfPath('/'.$this->show_folder.'/'.$this->class_folder, false);

        $images = [];
        if(isset($contents['images']))
            $images = $contents['images'];

        return $images;
    }

    public function proofPendingImages(): int
    {
        $images = $this->getImagesPendingProofing();

        $proofed = 0;
        if($images) {
            foreach($images as $image) {
                $image_path = $image->path();
                $proofs_path = '/proofs/'.$this->show_folder.'/'.$this->class_folder;
                $web_images_path = '/web_images/'.$this->show_folder.'/'.$this->class_folder;
                GenerateThumbnails::dispatch($image_path, $proofs_path)->onQueue('thumbnails');
                GenerateWebImage::dispatch($image_path, $web_images_path)->onQueue('thumbnails');
                $proofed++;
            }
        }

        return $proofed;
    }

    public function processPendingImages(): int
    {
        $images = $this->getImagesPendingProcessing();

        $processed = 0;
        if ($images) {
            $proof_number_count = count($images);
            $proof_numbers = Utility::generateProofNumbers($this->show_folder, $proof_number_count);
            foreach ($images as $image) {
                ImportPhoto::dispatch($image->path(), array_shift($proof_numbers));
                $processed++;
            }
        }

        return $processed;
    }

    public function processImage(string $image_path): void
    {
        $image_obj = new Image($image_path);
        $proof_numbers = Utility::generateProofNumbers($this->show_folder, 1);
        $image_obj->processImage(array_shift($proof_numbers), false);
    }
}
