<?php

namespace App\Livewire;

use App\Jobs\Photo\GenerateThumbnails;
use App\Jobs\ShowClass\ResetClassPhotos;
use App\Jobs\ShowClass\UploadHighresImages;
use App\Jobs\ShowClass\UploadProofs;
use App\Jobs\ShowClass\UploadWebImages;
use App\Models\Photo;
use App\Models\Show;
use App\Proofgen\Image;
use App\Proofgen\ShowClass;
use App\Services\PathResolver;
use App\Services\PhotoService;
use Exception;
use Flux\Flux;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class ClassViewComponent extends Component
{
    public string $show = '';

    public string $class = '';

    protected Show $showModel;

    protected \App\Models\ShowClass $showClass;

    public string $working_path = '';

    public string $working_full_path = '';

    public string $fullsize_base_path = '';

    public string $archive_base_path = '';

    public string $proofs_path = '';

    public string $web_images_path = '';

    public string $highres_images_path = '';

    public string $flash_message = '';

    public int $flash_message_set_at = 0;

    protected int $flash_message_max_length = 10;

    public bool $local_web_image_sync_performed = false;

    public bool $local_proofs_sync_performed = false;

    protected PathResolver $pathResolver;

    // Bulk action properties
    public array $selectedPhotos = [];

    public string $selectedAction = '';

    public bool $showMoveModal = false;

    public bool $showDeleteModal = false;

    public string $targetClass = '';

    public bool $deleteFiles = false;

    public function mount(PathResolver $pathResolver): void
    {
        $this->pathResolver = $pathResolver;
        $this->fullsize_base_path = config('proofgen.fullsize_home_dir');
        $this->archive_base_path = config('proofgen.archive_home_dir');
        $this->working_path = $this->show.'/'.$this->class;
        $this->proofs_path = $this->pathResolver->getProofsPath($this->show, $this->class);
        $this->web_images_path = $this->pathResolver->getWebImagesPath($this->show, $this->class);
        $this->highres_images_path = $this->pathResolver->getHighresImagesPath($this->show, $this->class);
    }

    public function boot(): void
    {
        $this->showModel = Show::find($this->show);
        $this->loadShowClass();

        // Check if the flash message is set and if it has expired
        if ($this->flash_message_set_at > 0 && (time() - $this->flash_message_set_at) > $this->flash_message_max_length) {
            $this->setFlashMessage('');
        }
    }

    public function loadShowClass(): void
    {
        $this->showClass = \App\Models\ShowClass::with('photos')->find($this->show.'_'.$this->class);
        if (! $this->local_web_image_sync_performed) {
            $this->showClass->localWebImageSync();
            $this->local_web_image_sync_performed = true;
        }
        if (! $this->local_proofs_sync_performed) {
            $this->showClass->localProofsSync();
            $this->local_proofs_sync_performed = true;
        }
    }

    public function render()
    {
        $pre_existing_photos_imported = $this->showClass->importExistingPhotosFromOriginalsDirectory();

        // If we imported any photos, we need to load the show class again
        if ($pre_existing_photos_imported > 0) {
            $this->loadShowClass();
        }

        $photos_pending_import = $this->showClass->getImagesPendingImport();

        $counts = $this->showClass->processingCounts();

        $photos_imported = $counts['photos_imported'];
        $photos_proofed = $counts['photos_proofed'];
        $photos_pending_proofs = $counts['photos_pending_proofs'];
        $photos_proofs_uploaded = $counts['photos_proofs_uploaded'];
        $photos_pending_proof_uploads = $counts['photos_pending_proof_uploads'];
        $photos_web_images_generated = $counts['photos_web_images_generated'];
        $photos_pending_web_images = $counts['photos_pending_web_images'];
        $photos_web_images_uploaded = $counts['photos_web_images_uploaded'];
        $photos_pending_web_image_uploads = $counts['photos_pending_web_image_uploads'];
        $photos_highres_images_generated = $counts['photos_highres_images_generated'];
        $photos_pending_highres_images = $counts['photos_pending_highres_images'];
        $photos_highres_images_uploaded = $counts['photos_highres_images_uploaded'];
        $photos_pending_highres_image_uploads = $counts['photos_pending_highres_image_uploads'];

        return view('livewire.class-view-component')
            ->with('show', $this->showModel)
            ->with('show_class', $this->showClass)
            ->with('photos', $this->showClass->photos()->with('metadata')->get())
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
            ->title($this->show.' '.$this->class.' - Proofgen');
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

    public function checkProofAndWebImageUploads(): void
    {
        $images_pending_upload = count($this->showClass->pendingProofUploads());
        $web_images_pending_upload = count($this->showClass->pendingWebImageUploads());
        $highres_images_pending_upload = count($this->showClass->pendingHighresImageUploads());

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

    public function fixMissingMetadataOnPhoto(string $photo_id): void
    {
        /** @var Photo $photo */
        $photo = $this->showClass->photos()->where('id', $photo_id)->first();
        Log::debug('Fixing metadata for photo: '.$photo_id);
        if ($photo) {
            Log::debug('Found Photo record: ', $photo->toArray());
        }
        if ($photo) {
            $metadata = $photo->createMetadataRecord();
            Flux::toast(
                text: 'Metadata fixed for '.$photo->proof_number,
                heading: 'Info',
                variant: 'success',
                position: 'top right'
            );
        } else {
            Flux::toast(
                text: 'Photo not found',
                heading: 'Error',
                variant: 'error',
                position: 'top right'
            );
        }
    }

    public function importPendingImages(): void
    {
        $pathResolver = $this->pathResolver ?? app(PathResolver::class);
        $show_class = new ShowClass($this->show, $this->class, $pathResolver);
        $count = $show_class->processPendingImages();
        $this->setFlashMessage($count.' Images queued for import.');
    }

    /**
     * @throws Exception
     */
    public function processImage($image_path): void
    {
        $pathResolver = $this->pathResolver ?? app(PathResolver::class);
        $show_class = new ShowClass($this->show, $this->class, $pathResolver);
        $show_class->processImage($image_path);
        $this->setFlashMessage($image_path.' Processed.');
    }

    public function proofPendingPhotos(): void
    {
        $count = $this->showClass->proofPendingPhotos();
        $this->setFlashMessage($count.' Photos queued.');
    }

    public function webImagePendingPhotos(): void
    {
        $count = $this->showClass->webImagePendingPhotos();
        $this->setFlashMessage($count.' Photos queued.');
    }

    public function highresImagePendingPhotos(): void
    {
        $count = $this->showClass->highresImagePendingPhotos();
        $this->setFlashMessage($count.' Photos queued.');
    }

    public function proofPhoto(string $photo_id): void
    {
        $photo = $this->showClass->photos()->where('id', $photo_id)->first();
        if (! $photo) {
            $this->setFlashMessage('Photo not found');

            return;
        }

        GenerateThumbnails::dispatch($photo->id, $photo->proofs_path)->onQueue('thumbnails');

        Flux::toast(
            text: 'Proofs queued for '.$photo->proof_number,
            heading: 'Info',
            variant: 'success',
            position: 'top right'
        );
    }

    public function generateWebImage(string $photo_id): void
    {
        $photo = $this->showClass->photos()->where('id', $photo_id)->first();
        if (! $photo) {
            $this->setFlashMessage('Photo not found');

            return;
        }

        // Get PathResolver instance for this request
        $pathResolver = $this->pathResolver ?? app(PathResolver::class);
        $web_images_path = $pathResolver->getWebImagesPath($this->showModel->name, $this->showClass->name);
        $photoService = app(PhotoService::class);
        try {
            $web_image_path = $photoService->generateWebImage($photo->id, $web_images_path);
            Log::debug('Generated web image: '.$web_image_path);
            if ($web_image_path) {
                $photo->web_image_generated_at = now();
                $photo->save();
                Flux::toast(
                    text: 'Web image generated: '.$photo->proof_number,
                    heading: 'Info',
                    variant: 'success',
                    position: 'top right'
                );
            } else {
                Flux::toast(
                    text: 'Web image generation failed',
                    heading: 'Error',
                    variant: 'error',
                    position: 'top right'
                );
            }
        } catch (Exception $e) {
            Log::error('Error generating web image: '.$e->getMessage());
            Log::debug('Web Image generation attempted with photo_id: '.$photo->id.' and web_images_path: '.$web_images_path);
            $this->setFlashMessage('Error generating web image: '.$e->getMessage());

            return;
        }
    }

    public function generateHighresImage(string $photo_id): void
    {
        $photo = $this->showClass->photos()->where('id', $photo_id)->first();
        if (! $photo) {
            $this->setFlashMessage('Photo not found');

            return;
        }

        // Get PathResolver instance for this request
        $pathResolver = $this->pathResolver ?? app(PathResolver::class);
        $highres_images_path = $pathResolver->getHighresImagesPath($this->showModel->name, $this->showClass->name);
        $photoService = app(PhotoService::class);
        try {
            $highres_image_path = $photoService->generateHighresImage($photo->id, $highres_images_path);
            Log::debug('Generated highres image: '.$highres_image_path);
            if ($highres_image_path) {
                $photo->highres_image_generated_at = now();
                $photo->save();
                Flux::toast(
                    text: 'Highres image generated: '.$photo->proof_number,
                    heading: 'Info',
                    variant: 'success',
                    position: 'top right'
                );
            } else {
                Flux::toast(
                    text: 'Highres image generation failed',
                    heading: 'Error',
                    variant: 'error',
                    position: 'top right'
                );
            }
        } catch (Exception $e) {
            Log::error('Error generating highres image: '.$e->getMessage());
            Log::debug('Highres Image generation attempted with photo_id: '.$photo->id.' and highres_images_path: '.$highres_images_path);
            $this->setFlashMessage('Error generating highres image: '.$e->getMessage());

            return;
        }
    }

    public function regenerateProofs(): void
    {
        $photos_queued = $this->showClass->regenerateProofs();

        Flux::toast(
            text: number_format($photos_queued).' photos queued to regenerate proofs for '.$this->show.' '.$this->class,
            heading: 'Info',
            variant: 'success',
            position: 'top right'
        );
    }

    public function regenerateWebImages(): void
    {
        $photos_queued = $this->showClass->regenerateWebImages();

        Flux::toast(
            text: number_format($photos_queued).' photos queued to regenerate web images for '.$this->show.' '.$this->class,
            heading: 'Info',
            variant: 'success',
            position: 'top right'
        );
    }

    public function regenerateHighresImages(): void
    {
        $photos_queued = $this->showClass->regenerateHighresImages();

        Flux::toast(
            text: number_format($photos_queued).' photos queued to regenerate highres images for '.$this->show.' '.$this->class,
            heading: 'Info',
            variant: 'success',
            position: 'top right'
        );
    }

    public function resetPhotos(): void
    {
        ResetClassPhotos::dispatch($this->showClass->show_id, $this->class);

        Flux::toast(
            text: 'Photos queued to reset for '.$this->show.' '.$this->class,
            heading: 'Info',
            variant: 'success',
            position: 'top right'
        );
    }

    public function uploadPendingProofsAndWebImages(): void
    {
        // Photos pending proof uploads
        $photos_proofed_not_uploaded = $this->showClass->photosProofedNotUploaded()->get();
        $photos_queued_for_upload = 0;
        if ($photos_proofed_not_uploaded->count()) {
            UploadProofs::dispatch($this->show, $this->class);
            $photos_queued_for_upload = $photos_proofed_not_uploaded->count();
        }

        // Photos pending web image uploads
        $photos_web_images_not_uploaded = $this->showClass->photosWebImagedNotUploaded()->get();
        $web_images_queued_for_upload = 0;
        if ($photos_web_images_not_uploaded->count()) {
            UploadWebImages::dispatch($this->show, $this->class);
            $web_images_queued_for_upload = $photos_web_images_not_uploaded->count();
        }

        // Photos pending highres image uploads
        $photos_highres_images_not_uploaded = $this->showClass->photosHighresImagedNotUploaded()->get();
        $highres_images_queued_for_upload = 0;
        if ($photos_highres_images_not_uploaded->count()) {
            UploadHighresImages::dispatch($this->show, $this->class);
            $highres_images_queued_for_upload = $photos_highres_images_not_uploaded->count();
        }

        $message = '';
        $total_queued = $photos_queued_for_upload + $web_images_queued_for_upload + $highres_images_queued_for_upload;

        if ($total_queued === 0) {
            $message = 'Nothing to upload.';
        } else {
            $parts = [];

            if ($photos_queued_for_upload > 0) {
                $parts[] = $photos_queued_for_upload.' Photos';
            }

            if ($web_images_queued_for_upload > 0) {
                $parts[] = $web_images_queued_for_upload.' Web Images';
            }

            if ($highres_images_queued_for_upload > 0) {
                $parts[] = $highres_images_queued_for_upload.' Highres Images';
            }

            $message = implode(', ', $parts).' queued for upload.';
        }

        Flux::toast(
            text: $message,
            heading: 'Info',
            variant: 'success',
            position: 'top right'
        );
    }

    public function openFolder(string $path): void
    {
        if (! file_exists($path)) {
            Flux::toast(
                text: 'Folder not found: '.$path,
                heading: 'Error',
                variant: 'error',
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

    public function humanReadableFilesize($bytes): string
    {
        if ($bytes > 0) {
            $base = floor(log($bytes) / log(1024));
            $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB']; // units of measurement

            return number_format(($bytes / pow(1024, floor($base))), 2)." $units[$base]";
        } else {
            return '0 bytes';
        }
    }

    // Bulk action methods
    public function performBulkAction(): void
    {
        switch ($this->selectedAction) {
            case 'move':
                $this->showMoveModal = true;
                break;
            case 'delete':
                $this->showDeleteModal = true;
                break;
        }
    }

    public function moveSelectedPhotos(): void
    {
        if (empty($this->targetClass)) {
            Flux::toast('Please select a target class', 'Error', 'error');

            return;
        }

        $photoMoveService = app(\App\Services\PhotoMoveService::class);
        $results = $photoMoveService->movePhotos($this->selectedPhotos, $this->targetClass);

        if (count($results['success']) > 0) {
            Flux::toast(
                count($results['success']).' photos moved successfully',
                'Success',
                'success'
            );
        }

        if (count($results['errors']) > 0) {
            foreach ($results['errors'] as $photoId => $error) {
                Flux::toast($error, 'Error', 'error');
            }
        }

        // Reset state
        $this->selectedPhotos = [];
        $this->showMoveModal = false;
        $this->targetClass = '';
        $this->selectedAction = '';
    }

    public function deleteSelectedPhotos(): void
    {
        $deletedCount = 0;

        foreach ($this->selectedPhotos as $photoId) {
            $photo = Photo::find($photoId);
            if ($photo) {
                if ($this->deleteFiles) {
                    // Delete all associated files
                    $photo->deleteLocalProofs();
                    $photo->deleteLocalWebImage();
                    $photo->deleteLocalHighresImage();

                    // Delete original
                    if (file_exists($photo->full_path)) {
                        unlink($photo->full_path);
                    }
                }

                $photo->delete();
                $deletedCount++;
            }
        }

        Flux::toast(
            $deletedCount.' photos deleted',
            'Success',
            'success'
        );

        // Reset state
        $this->selectedPhotos = [];
        $this->showDeleteModal = false;
        $this->deleteFiles = false;
        $this->selectedAction = '';
    }
}
