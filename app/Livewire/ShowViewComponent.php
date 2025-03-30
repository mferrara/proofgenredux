<?php

namespace App\Livewire;

use App\Jobs\ShowClass\ImportPhotos;
use App\Proofgen\Show;
use App\Proofgen\ShowClass;
use App\Proofgen\Utility;
use App\Services\PathResolver;
use Livewire\Component;

class ShowViewComponent extends Component
{
    public string $working_path = '';
    public string $show_id = '';
    protected \App\Models\Show $show;
    public string $fullsize_base_path = '';
    public string $archive_base_path = '';
    public string $working_full_path = '';
    public string $flash_message = '';
    public bool $check_proofs_uploaded = false;
    protected PathResolver $pathResolver;

    public function mount(PathResolver $pathResolver)
    {
        $this->pathResolver = $pathResolver;
        $this->fullsize_base_path = config('proofgen.fullsize_home_dir');
        $this->archive_base_path = config('proofgen.archive_home_dir');
        $this->working_path = $this->show_id;
    }

    public function boot()
    {
        $this->show = \App\Models\Show::find($this->show_id);
    }

    public function render()
    {
        // Get PathResolver instance on each render for Livewire polling
        $pathResolver = $this->pathResolver ?? app(PathResolver::class);

        $this->working_full_path = $pathResolver->getAbsolutePath($this->working_path, $this->fullsize_base_path);

        $current_path_directories = Utility::getDirectoriesOfPath($this->working_path);

        $class_folders = [];
        foreach ($current_path_directories as $directory) {
            $class = explode('/', $directory);
            $class = end($class);

            // Check if we have this database record
            if( ! $this->show->hasClass($class)) {
                $this->show->addClass($class);
            }

            $show_class = new ShowClass($this->show->id, $class, $pathResolver);
            $images_to_process = $show_class->getImagesPendingProcessing();
            $images_to_proof = $show_class->getImagesPendingProofing();
            $images_to_web = $show_class->getImagesPendingWeb();
            $images_imported = $show_class->getImportedImages();
            $folder_name = explode('/', $directory);
            $folder_name = end($folder_name);
            $class_folders[] = [
                'path' => $folder_name,
                'images_pending_processing_count' => count($images_to_process),
                'images_pending_proofing_count' => count($images_to_proof),
                'images_pending_web_count' => count($images_to_web),
                'images_imported' => count($images_imported),
                'show_class' => $this->show->classes()->where('id', $this->show->id.'_'.$folder_name)->first(),
            ];
        }

        $images_pending_upload = [];
        $web_images_pending_upload = [];
        if($this->check_proofs_uploaded)
        {
            $show = new Show($this->show->id, $pathResolver);
            $images_pending_upload = $show->pendingProofUploads();
            $web_images_pending_upload = $show->pendingWebImageUploads();
        }

        // Reorder the class folders by the path, alphabetically
        usort($class_folders, function($a, $b) {
            return strcmp($a['path'], $b['path']);
        });

        return view('livewire.show-view-component')
            ->with('show', $this->show)
            ->with('current_path_directories', $current_path_directories)
            ->with('class_folders', $class_folders)
            ->with('images_pending_upload', $images_pending_upload)
            ->with('web_images_pending_upload', $web_images_pending_upload)
            ->title($this->show->id.' - Proofgen');
    }

    public function processPendingImages(string $class_folder): void
    {
        $pathResolver = $this->pathResolver ?? app(PathResolver::class);
        $show_class = new ShowClass($this->show->id, $class_folder, $pathResolver);
        ImportPhotos::dispatch($this->show->id, $class_folder)->onQueue('imports');
        $this->flash_message = $class_folder.' queued for import.';
        $this->check_proofs_uploaded = false;
    }

    public function checkProofsUploaded(): void
    {
        $this->check_proofs_uploaded = true;
    }

    public function uploadPendingProofs(string $show_folder): void
    {
        $pathResolver = $this->pathResolver ?? app(PathResolver::class);
        $show = new Show($this->show->id, $pathResolver);
        $uploaded = $show->uploadPendingProofs();
        if(count($uploaded) === 0)
        {
            $this->flash_message = 'No images to upload.';
            $this->check_proofs_uploaded = false;
            return;
        } else {
            $this->flash_message = count($uploaded).' Images uploaded.';
        }
    }

    public function uploadPendingProofsAndWebImages(): void
    {
        $pathResolver = $this->pathResolver ?? app(PathResolver::class);
        $show = new Show($this->show->id, $pathResolver);
        $uploaded = $show->uploadPendingProofs();
        $web_images = $show->uploadPendingWebImages();
        if(count($uploaded) === 0 && count($web_images) === 0)
        {
            $this->flash_message = 'No images to upload.';
            $this->check_proofs_uploaded = false;
            return;
        } else {
            if(count($uploaded) > 0)
                $this->flash_message = count($uploaded).' Images uploaded';
            if(count($web_images) > 0) {
                if(count($uploaded) > 0)
                    $this->flash_message .= ' and ';
                $this->flash_message .= count($web_images).' Web Images uploaded';
            }

            if(strlen($this->flash_message) > 0)
                $this->flash_message .= '.';
        }
        $this->check_proofs_uploaded = false;
    }

    public function regenerateProofs(string $class_folder): void
    {
        $pathResolver = $this->pathResolver ?? app(PathResolver::class);
        $show_class = new ShowClass($this->show->id, $class_folder, $pathResolver);
        $show_class->regenerateProofs();
        $this->flash_message = 'Queued';
        $this->check_proofs_uploaded = false;
    }

    public function regenerateWebImages(string $class_folder): void
    {
        $pathResolver = $this->pathResolver ?? app(PathResolver::class);
        $show_class = new ShowClass($this->show->id, $class_folder, $pathResolver);
        $show_class->regenerateWebImages();
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
}
