<?php

namespace App\Livewire;

use App\Helpers\DirectoryNameValidator;
use App\Jobs\ShowClass\ImportClassPhotos;
use App\Jobs\ShowClass\ResetClassPhotos;
use App\Models\ShowClass as ShowClassModel;
use App\Proofgen\ShowClass;
use App\Proofgen\Utility;
use App\Services\PathResolver;
use Flux\Flux;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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

    public function mount()
    {
        $this->fullsize_base_path = config('proofgen.fullsize_home_dir');
        $this->archive_base_path = config('proofgen.archive_home_dir');
        $this->working_path = $this->show_id;

        // One-time discovery of class folders on the filesystem that don't yet
        // have a ShowClass record. Runs once on initial page load — render() is
        // read-only after this so 5-second polling doesn't trigger DB writes.
        $this->discoverClasses();
    }

    public function boot()
    {
        $this->show = \App\Models\Show::find($this->show_id);
    }

    private function discoverClasses(): void
    {
        $directories = Utility::getDirectoriesOfPath($this->working_path);
        $existingClassIds = $this->show->classes()->pluck('id')->all();

        foreach ($directories as $directory) {
            $folder_name = basename($directory);
            if (! DirectoryNameValidator::isValid($folder_name)) {
                continue;
            }
            $class_id = $this->show->id.'_'.$folder_name;
            if (! in_array($class_id, $existingClassIds, true)) {
                $this->show->addClass($folder_name);
            }
        }
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
        $pathResolver = app(PathResolver::class);

        $this->working_full_path = $pathResolver->getAbsolutePath($this->working_path, $this->fullsize_base_path);

        $current_path_directories = Utility::getDirectoriesOfPath($this->working_path);

        // Eager-load class models once instead of querying per directory.
        $class_models = $this->show->classes()->get()->keyBy('id');

        $class_folders = [];
        foreach ($current_path_directories as $directory) {
            $folder_name = basename($directory);

            $is_valid_directory = DirectoryNameValidator::isValid($folder_name);
            $validation_error = $is_valid_directory ? null : DirectoryNameValidator::getValidationError($folder_name);
            $suggested_name = $is_valid_directory ? null : DirectoryNameValidator::suggestValidName($folder_name);

            $show_class_model = null;
            $images_to_process = [];
            $images_to_web = [];
            $images_imported = [];

            if ($is_valid_directory) {
                $show_class_model = $class_models->get($this->show->id.'_'.$folder_name);

                // Legacy procedural ShowClass for filesystem counts (not yet migrated to model methods).
                $show_class = new ShowClass($this->show->id, $folder_name, $pathResolver);
                $images_to_process = $show_class->getImagesPendingProcessing();
                $images_to_web = $show_class->getImagesPendingWeb();
                $images_imported = $show_class->getImportedImages();
            }

            $class_folders[] = [
                'path' => $folder_name,
                'images_pending_processing_count' => count($images_to_process),
                'images_pending_web_count' => count($images_to_web),
                'images_imported' => count($images_imported),
                'show_class' => $show_class_model,
                'is_valid' => $is_valid_directory,
                'validation_error' => $validation_error,
                'suggested_name' => $suggested_name,
            ];
        }

        // Reorder the class folders by the path, alphabetically
        usort($class_folders, function ($a, $b) {
            return strcmp($a['path'], $b['path']);
        });

        // Pass relation Builders (not ->get()) so the action-panel and
        // photo-process-status-table partials' ->count() calls become
        // SQL COUNT(*) instead of SELECT * + PHP count.
        return view('livewire.show-view-component', [
            'show' => $this->show,
            'current_path_directories' => $current_path_directories,
            'class_folders' => $class_folders,
            'photos_pending_import' => $this->show->getImagesPendingImport(),
            'photos_imported' => $this->show->photos(),
            'photos_proofed' => $this->show->photosProofed(),
            'photos_pending_proofs' => $this->show->photosNotProofed(),
            'photos_proofs_uploaded' => $this->show->photosProofsUploaded(),
            'photos_pending_proof_uploads' => $this->show->photosProofedNotUploaded(),
            'photos_web_images_generated' => $this->show->photosWebImaged(),
            'photos_pending_web_images' => $this->show->photosNotWebImaged(),
            'photos_web_images_uploaded' => $this->show->photosWebImagesUploaded(),
            'photos_pending_web_image_uploads' => $this->show->photosWebImagedNotUploaded(),
            'photos_highres_images_generated' => $this->show->photosHighresImaged(),
            'photos_pending_highres_images' => $this->show->photosNotHighresImaged(),
            'photos_highres_images_uploaded' => $this->show->photosHighresImagesUploaded(),
            'photos_pending_highres_image_uploads' => $this->show->photosHighresImagedNotUploaded(),
            'web_images_enabled' => config('proofgen.generate_web_images.enabled', true),
            'highres_images_enabled' => config('proofgen.generate_highres_images.enabled', true),
        ])->title($this->show->id.' - Proofgen');
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
        // Validate directory name before processing
        if (! DirectoryNameValidator::isValid($class_folder)) {
            $error = DirectoryNameValidator::getValidationError($class_folder);
            $suggested = DirectoryNameValidator::suggestValidName($class_folder);
            Flux::toast(
                text: "Cannot import from '{$class_folder}': {$error} Suggested name: '{$suggested}'",
                heading: 'Invalid Directory Name',
                variant: 'danger',
                position: 'top right'
            );

            return;
        }

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

    public function uploadPendingProofs(): void
    {
        \Illuminate\Support\Facades\Bus::dispatch(new \App\Jobs\Show\UploadShowProofs($this->show->id));
        $this->setFlashMessage('Proof uploads queued for '.$this->show->id.'.');
    }

    public function uploadPendingProofsAndWebImages(): void
    {
        // Chain proofs → web → highres so customer-visible proofs hit
        // the server first; web/highres run sequentially after.
        \Illuminate\Support\Facades\Bus::chain([
            new \App\Jobs\Show\UploadShowProofs($this->show->id),
            new \App\Jobs\Show\UploadShowWebImages($this->show->id),
            new \App\Jobs\Show\UploadShowHighresImages($this->show->id),
        ])->dispatch();

        $this->setFlashMessage('Uploads queued for '.$this->show->id.'.');
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

    public function openFolder(string $path): void
    {
        if (! file_exists($path)) {
            Flux::toast(
                text: 'Folder not found: '.$path,
                heading: 'Error',
                variant: 'danger',
                position: 'top right'
            );

            return;
        }

        // On Mac, use the 'open' command to open a folder in Finder
        if (PHP_OS === 'Darwin') { // Darwin is the core of macOS
            exec('open "'.$path.'"');

            Flux::toast(
                text: 'Opening folder in Finder',
                heading: 'Info',
                variant: 'success',
                position: 'top right'
            );
        } else {
            Flux::toast(
                text: 'Opening folders is only supported on macOS',
                heading: 'Warning',
                variant: 'warning',
                position: 'top right'
            );
        }
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

    public function renameClassDirectory(string $old_name, string $new_name): void
    {
        // Validate the new name
        if (! DirectoryNameValidator::isValid($new_name)) {
            $error = DirectoryNameValidator::getValidationError($new_name);
            Flux::toast(
                text: "The new name '{$new_name}' is invalid: {$error}",
                heading: 'Invalid Directory Name',
                variant: 'danger',
                position: 'top right'
            );

            return;
        }

        // Check if the new directory already exists
        $old_path = $this->working_path.'/'.$old_name;
        $new_path = $this->working_path.'/'.$new_name;

        if (Storage::disk('fullsize')->exists($new_path)) {
            Flux::toast(
                text: "A directory with the name '{$new_name}' already exists.",
                heading: 'Directory Exists',
                variant: 'danger',
                position: 'top right'
            );

            return;
        }

        try {
            // Rename the directory
            if (Storage::disk('fullsize')->move($old_path, $new_path)) {
                Flux::toast(
                    text: "Directory renamed from '{$old_name}' to '{$new_name}'.",
                    heading: 'Success',
                    variant: 'success',
                    position: 'top right'
                );

                Log::info("Renamed directory from '{$old_path}' to '{$new_path}'");
            } else {
                throw new \Exception('Failed to rename directory');
            }
        } catch (\Exception $e) {
            Log::error("Failed to rename directory: {$e->getMessage()}", [
                'old_path' => $old_path,
                'new_path' => $new_path,
            ]);

            Flux::toast(
                text: 'Failed to rename directory. Please check permissions.',
                heading: 'Error',
                variant: 'danger',
                position: 'top right'
            );
        }
    }

    public function renameImportedClass(string $old_name, string $new_name): void
    {
        // Get the ShowClass model
        $classId = $this->show_id.'_'.$old_name;
        $showClass = ShowClassModel::find($classId);

        if (! $showClass) {
            Flux::toast(
                text: "Class '{$old_name}' not found.",
                heading: 'Error',
                variant: 'danger',
                position: 'top right'
            );

            return;
        }

        // Use the ClassRenameService to handle the rename
        $renameService = app(\App\Services\ClassRenameService::class);
        $result = $renameService->renameClass($showClass, $new_name);

        if ($result['success']) {
            Flux::toast(
                text: $result['message'],
                heading: 'Success',
                variant: 'success',
                position: 'top right'
            );
        } else {
            Flux::toast(
                text: $result['error'],
                heading: 'Error',
                variant: 'danger',
                position: 'top right'
            );
        }
    }
}
