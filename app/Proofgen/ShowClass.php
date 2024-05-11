<?php

namespace App\Proofgen;

class ShowClass
{
    protected string $show_folder = '';
    protected string $class_folder = '';
    protected string $fullsize_base_path = '';
    protected string $archive_base_path = '';

    public function __construct(string $show_folder, string $class_folder)
    {
        $this->show_folder = $show_folder;
        $this->class_folder = $class_folder;
        $this->fullsize_base_path = config('proofgen.fullsize_home_dir');
        $this->archive_base_path = config('proofgen.archive_home_dir');
    }

    public function getImagesPendingProofing(): array
    {
        // Get contents of the originals directory and compare to contents of the proofs directory
        $originals = Utility::getContentsOfPath('/'.$this->show_folder.'/'.$this->class_folder.'/originals', false);
        $proofs = Utility::getContentsOfPath('/'.$this->show_folder.'/'.$this->class_folder.'/proofs', false);

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

    public function processPendingImages(): int
    {
        $images = $this->getImagesPendingProcessing();

        $processed = 0;
        if ($images) {
            $proof_number_count = count($images);
            $proof_numbers = Utility::generateProofNumbers($this->show_folder, $proof_number_count);
            foreach ($images as $image) {
                $image_path = $image->path();
                $image_obj = new Image($image_path);
                $image_obj->processImage(array_shift($proof_numbers), true);
                $processed++;
            }
        }

        return $processed;
    }

    public function processImage(string $image_path): void
    {
        $image_obj = new Image($image_path);
        $proof_numbers = Utility::generateProofNumbers($this->show_folder, 1);
        $image_obj->processImage(array_shift($proof_numbers), true);
    }
}
