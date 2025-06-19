<?php

namespace App\Livewire;

use App\Jobs\ShowClass\ImportClassPhotos;
use App\Jobs\ShowClass\ResetClassPhotos;
use App\Proofgen\ShowClass;
use App\Proofgen\Utility;
use App\Services\PathResolver;
use Flux\Flux;
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

    public int $flash_message_set_at = 0;

    protected int $flash_message_max_length = 10;

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

    public function hydrate()
    {
        // Check if the flash message is set and if it has expired
        if ($this->flash_message_set_at > 0 && (time() - $this->flash_message_set_at) > $this->flash_message_max_length) {
            $this->setFlashMessage('');
        }
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
            if (! $this->show->hasClass($class)) {
                $this->show->addClass($class);
            }

            // Get the model
            $show_class_model = $this->show->classes()->where('id', $this->show->id.'_'.$class)->first();

            // Create the legacy class just for these counting methods that aren't yet migrated
            $show_class = new ShowClass($this->show->id, $class, $pathResolver);
            $images_to_process = $show_class->getImagesPendingProcessing();
            $images_to_web = $show_class->getImagesPendingWeb();
            $images_imported = $show_class->getImportedImages();

            $folder_name = explode('/', $directory);
            $folder_name = end($folder_name);
            $class_folders[] = [
                'path' => $folder_name,
                'images_pending_processing_count' => count($images_to_process),
                'images_pending_web_count' => count($images_to_web),
                'images_imported' => count($images_imported),
                'show_class' => $show_class_model,
            ];
        }

        // Reorder the class folders by the path, alphabetically
        usort($class_folders, function ($a, $b) {
            return strcmp($a['path'], $b['path']);
        });

        $photos_pending_import = $this->show->getImagesPendingImport();
        $photos_imported = $this->show->photos()->get();
        $photos_proofed = $this->show->photosProofed()->get();
        $photos_pending_proofs = $this->show->photosNotProofed()->get();
        $photos_pending_proof_uploads = $this->show->photosProofedNotUploaded()->get();
        $photos_proofs_uploaded = $this->show->photosProofsUploaded()->get();
        $photos_web_images_generated = $this->show->photosWebImaged()->get();
        $photos_pending_web_images = $this->show->photosNotWebImaged()->get();
        $photos_web_images_uploaded = $this->show->photosWebImaged()->get();
        $photos_pending_web_image_uploads = $this->show->photosWebImagedNotUploaded()->get();
        $photos_highres_images_generated = $this->show->photosHighresImaged()->get();
        $photos_pending_highres_images = $this->show->photosNotHighresImaged()->get();
        $photos_highres_images_uploaded = $this->show->photosHighresImagesUploaded()->get();
        $photos_pending_highres_image_uploads = $this->show->photosHighresImagedNotUploaded()->get();

        return view('livewire.show-view-component')
            ->with('show', $this->show)
            ->with('current_path_directories', $current_path_directories)
            ->with('class_folders', $class_folders)
            ->with('photos_pending_import', $photos_pending_import)
            ->with('photos_imported', $photos_imported)
            ->with('photos_proofed', $photos_proofed)
            ->with('photos_pending_proofs', $photos_pending_proofs)
            ->with('photos_proofs_uploaded', $photos_proofs_uploaded)
            ->with('photos_pending_proof_uploads', $photos_pending_proof_uploads)
            ->with('photos_web_images_generated', $photos_web_images_generated)
            ->with('photos_pending_web_images', $photos_pending_web_images)
            ->with('photos_web_images_uploaded', $photos_web_images_uploaded)
            ->with('photos_pending_web_image_uploads', $photos_pending_web_image_uploads)
            ->with('photos_highres_images_generated', $photos_highres_images_generated)
            ->with('photos_pending_highres_images', $photos_pending_highres_images)
            ->with('photos_highres_images_uploaded', $photos_highres_images_uploaded)
            ->with('photos_pending_highres_image_uploads', $photos_pending_highres_image_uploads)
            ->with('web_images_enabled', config('proofgen.generate_web_images.enabled', true))
            ->with('highres_images_enabled', config('proofgen.generate_highres_images.enabled', true))
            ->title($this->show->id.' - Proofgen');
    }

    public function setFlashMessage(string $message): void
    {
        if ($message === '') {
            $this->flash_message = '';
            $this->flash_message_set_at = 0;

            return;
        }

        $this->flash_message = $message;
        $this->flash_message_set_at = time();
    }

    public function processPendingClassImages(string $class_folder): void
    {
        ImportClassPhotos::dispatch($this->show->id, $class_folder)->onQueue('imports');
        $this->setFlashMessage($class_folder.' queued for import.');
    }

    public function importPendingImages(): void
    {
        $queued = $this->show->importPendingImages();
        $this->setFlashMessage($queued.' Images queued for import.');
    }

    public function checkProofAndWebImageUploads(): void
    {
        $response = $this->show->checkAllUploads();
        $images_pending_upload = count($response['images_pending_upload']);
        $web_images_pending_upload = count($response['web_images_pending_upload']);
        $highres_images_pending_upload = count($response['highres_images_pending_upload'] ?? []);

        $total_pending = $images_pending_upload + $web_images_pending_upload + $highres_images_pending_upload;

        if ($total_pending > 0) {
            $parts = [];

            if ($images_pending_upload > 0) {
                $parts[] = $images_pending_upload.' Images';
            }

            if ($web_images_pending_upload > 0) {
                $parts[] = $web_images_pending_upload.' Web Images';
            }

            if ($highres_images_pending_upload > 0) {
                $parts[] = $highres_images_pending_upload.' Highres Images';
            }

            $flash_message = 'Pending uploads: '.implode(', ', $parts);
        } else {
            $flash_message = 'No uploads pending';
        }

        $this->setFlashMessage($flash_message);
    }

    public function uploadPendingProofs(string $show_folder): void
    {
        $uploaded = $this->show->proofUploads();

        if (count($uploaded) === 0) {
            $flash_message = 'No images to upload.';
        } else {
            $flash_message = count($uploaded).' Images uploaded.';
        }

        $this->setFlashMessage($flash_message);
    }

    public function uploadPendingProofsAndWebImages(): void
    {
        $uploaded = $this->show->proofUploads();
        $web_images = $this->show->webImageUploads();
        $highres_images = $this->show->highresImageUploads();

        $total = count($uploaded) + count($web_images) + count($highres_images);

        if ($total === 0) {
            $flash_message = 'No images to upload.';
        } else {
            $parts = [];

            if (count($uploaded) > 0) {
                $parts[] = count($uploaded).' Images';
            }

            if (count($web_images) > 0) {
                $parts[] = count($web_images).' Web Images';
            }

            if (count($highres_images) > 0) {
                $parts[] = count($highres_images).' Highres Images';
            }

            $flash_message = implode(', ', $parts).' uploaded.';
        }

        $this->setFlashMessage($flash_message);
    }

    public function regenerateProofs(): void
    {
        $count = 0;
        foreach ($this->show->classes as $showClass) {
            $count += $showClass->regenerateProofs();
        }
        $this->setFlashMessage($count.' Proofs queued.');
    }

    public function regenerateWebImages(): void
    {
        $count = 0;
        foreach ($this->show->classes as $showClass) {
            $count += $showClass->regenerateWebImages();
        }
        $this->setFlashMessage($count.' Web Images queued.');
    }

    public function regenerateHighresImages(): void
    {
        $count = 0;
        foreach ($this->show->classes as $showClass) {
            $count += $showClass->regenerateHighresImages();
        }
        $this->setFlashMessage($count.' Highres Images queued.');
    }

    public function resetPhotos(): void
    {
        foreach ($this->show->classes as $showClass) {
            ResetClassPhotos::dispatch($this->show_id, $showClass->name);
        }

        Flux::toast(
            text: 'Photos queued to reset for '.$this->show->name,
            heading: 'Info',
            variant: 'success',
            position: 'top right'
        );
    }

    public function proofPendingPhotos(): void
    {
        $count = 0;
        foreach ($this->show->classes as $showClass) {
            $count += $showClass->proofPendingPhotos();
        }
        $this->setFlashMessage($count.' Photos queued.');
    }

    public function webImagePendingPhotos(): void
    {
        $count = 0;
        foreach ($this->show->classes as $showClass) {
            $count += $showClass->webImagePendingPhotos();
        }
        $this->setFlashMessage($count.' Photos queued.');
    }

    public function highresImagePendingPhotos(): void
    {
        $count = 0;
        foreach ($this->show->classes as $showClass) {
            $count += $showClass->highresImagePendingPhotos();
        }
        $this->setFlashMessage($count.' Photos queued.');
    }

    public function getImagesOfPath($path): array
    {
        $files = Utility::getFiles($path);
        $images = [];
        foreach ($files as $file) {

            foreach (['jpg', 'jpeg'] as $ext) {
                if (str_contains(strtolower($file), $ext)) {
                    $images[] = $file;
                }
            }
        }

        return $images;
    }
}
