<?php

namespace App\Livewire;

use App\Proofgen\Image;
use App\Proofgen\ShowClass;
use App\Proofgen\Utility;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class ClassViewComponent extends Component
{
    public string $show = '';
    public string $class = '';
    public string $working_path = '';
    public string $working_full_path = '';
    public string $fullsize_base_path = '';
    public string $archive_base_path = '';
    public string $proofs_path = '';
    public string $web_images_path = '';
    public string $flash_message = '';
    public bool $check_proofs_uploaded = false;

    public function mount()
    {
        $this->fullsize_base_path = config('proofgen.fullsize_home_dir');
        $this->archive_base_path = config('proofgen.archive_home_dir');
        $this->working_path = $this->show.'/'.$this->class;
        $this->proofs_path = '/proofs/'.$this->show . '/' . $this->class;
        $this->web_images_path = '/web_images/'.$this->show . '/' . $this->class;
    }
    public function render()
    {
        $this->working_full_path = $this->fullsize_base_path . '/' . $this->working_path;

        $current_path_contents = Utility::getContentsOfPath($this->working_path, false);
        $current_path_directories = Utility::getDirectoriesOfPath($this->working_path);

        $show_class = new ShowClass($this->show, $this->class);
        Log::debug('Show: '.$this->show.' Class: '.$this->class);
        $images_pending_processing = $show_class->getImagesPendingProcessing();
        $images_pending_proofing = $show_class->getImagesPendingProofing();
        $images_pending_upload = [];
        if($this->check_proofs_uploaded)
            $images_pending_upload = $show_class->pendingProofUploads();
        $web_images_pending_upload = [];
        if($this->check_proofs_uploaded)
            $web_images_pending_upload = $show_class->pendingWebImageUploads();
        $images_imported = $show_class->getImportedImages();

        return view('livewire.class-view-component')
            ->with('current_path_contents', $current_path_contents)
            ->with('current_path_directories', $current_path_directories)
            ->with('images_pending_processing', $images_pending_processing)
            ->with('images_pending_proofing', $images_pending_proofing)
            ->with('images_pending_upload', $images_pending_upload)
            ->with('web_images_pending_upload', $web_images_pending_upload)
            ->with('images_imported', $images_imported);
    }

    public function checkProofsUploaded(): void
    {
        $this->check_proofs_uploaded = true;
    }

    public function processPendingImages(): void
    {
        $show_class = new ShowClass($this->show, $this->class);
        $count = $show_class->processPendingImages();
        $this->flash_message = $count.' Images processed.';
        $this->check_proofs_uploaded = false;
    }

    public function processImage($image_path): void
    {
        $show_class = new ShowClass($this->show, $this->class);
        $show_class->processImage($image_path);
        $this->flash_message = $image_path.' Processed.';
        $this->check_proofs_uploaded = false;
    }

    public function proofPendingImages(): void
    {
        $show_class = new ShowClass($this->show, $this->class);
        $count = $show_class->proofPendingImages();
        $this->flash_message = $count.' Images proofed.';
        $this->check_proofs_uploaded = false;
    }

    public function proofImage($image_path): void
    {
        Image::createThumbnails($image_path, $this->proofs_path);
        Image::createWebImage($image_path, $this->web_images_path);
        $this->flash_message = $image_path.' Proofed.';
        $this->check_proofs_uploaded = false;
    }

    public function uploadPendingProofsAndWebImages(): void
    {
        $show_class = new ShowClass($this->show, $this->class);
        $count = count($show_class->uploadPendingProofs());
        $this->flash_message = $count.' Proofs';

        $count = count($show_class->uploadPendingWebImages());
        if($count > 0) {
            $this->flash_message .= ' & ' . $count . ' Web Images';
        }

        $this->flash_message .= ' uploaded.';

        $this->check_proofs_uploaded = false;
    }

    public function getImagesOfPath($path): array
    {
        $files = Utility::getFiles($path);
        $images = [];
        foreach ($files as $file) {

            foreach(['jpg', 'jpeg'] as $ext) {
                if (str_contains(strtolower($file), $ext)) {
                    $images[] = $file;
                }
            }
        }
        return $images;
    }

    public function humanReadableFilesize($bytes): string
    {
        if ($bytes > 0) {
            $base = floor(log($bytes) / log(1024));
            $units = array("B", "KB", "MB", "GB", "TB", "PB", "EB", "ZB", "YB"); //units of measurement
            return number_format(($bytes / pow(1024, floor($base))), 2) . " $units[$base]";
        } else return "0 bytes";
    }
}
