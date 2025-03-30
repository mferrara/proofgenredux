<?php

namespace App\Livewire;

use App\Jobs\Photo\GenerateThumbnails;
use App\Jobs\Photo\GenerateWebImage;
use App\Jobs\ShowClass\UploadProofs;
use App\Models\Photo;
use App\Models\Show;
use App\Proofgen\Image;
use App\Proofgen\ShowClass;
use App\Proofgen\Utility;
use App\Services\PathResolver;
use Flux\Flux;
use Illuminate\Support\Facades\Log;
use League\Flysystem\FileAttributes;
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
    public string $flash_message = '';
    public bool $check_proofs_uploaded = false;
    protected PathResolver $pathResolver;

    public function mount(PathResolver $pathResolver)
    {
        $this->pathResolver = $pathResolver;
        $this->fullsize_base_path = config('proofgen.fullsize_home_dir');
        $this->archive_base_path = config('proofgen.archive_home_dir');
        $this->working_path = $this->show.'/'.$this->class;
        $this->proofs_path = $this->pathResolver->getProofsPath($this->show, $this->class);
        $this->web_images_path = $this->pathResolver->getWebImagesPath($this->show, $this->class);
    }

    public function boot()
    {
        $this->showModel = Show::find($this->show);
        $this->showClass = \App\Models\ShowClass::find($this->show.'_'.$this->class);
    }

    public function render()
    {
        // Get PathResolver instance on each render for Livewire polling
        $pathResolver = $this->pathResolver ?? app(PathResolver::class);

        $this->working_full_path = $pathResolver->getAbsolutePath($this->working_path, $this->fullsize_base_path);

        $current_path_contents = Utility::getContentsOfPath($this->working_path, false);
        $current_path_directories = Utility::getDirectoriesOfPath($this->working_path);

        $show_class = new ShowClass($this->show, $this->class, $pathResolver);
        $images_pending_processing = $show_class->getImagesPendingProcessing();
        $images_pending_proofing = $show_class->getImagesPendingProofing();
        $images_pending_upload = [];
        if($this->check_proofs_uploaded)
            $images_pending_upload = $show_class->pendingProofUploads();
        $web_images_pending_upload = [];
        if($this->check_proofs_uploaded)
            $web_images_pending_upload = $show_class->pendingWebImageUploads();
        $images_imported = $show_class->getImportedImages();

        // Loop through imported images ensuring we have database records for them
        foreach($images_imported as $image) {
            /** @var FileAttributes $image */
            $file_path = $image->path();
            $photo = $this->showClass->importPhotoFromPath($file_path);
        }

        return view('livewire.class-view-component')
            ->with('photos', $this->showClass->photos()->with('metadata')->get())
            ->with('current_path_contents', $current_path_contents)
            ->with('current_path_directories', $current_path_directories)
            ->with('images_pending_processing', $images_pending_processing)
            ->with('images_pending_proofing', $images_pending_proofing)
            ->with('images_pending_upload', $images_pending_upload)
            ->with('web_images_pending_upload', $web_images_pending_upload)
            ->with('images_imported', $images_imported)
            ->title($this->show.' '.$this->class.' - Proofgen');
    }

    public function fixMissingMetadataOnPhoto(string $photo_id): void
    {
        /** @var Photo $photo */
        $photo = $this->showClass->photos()->where('id', $photo_id)->first();
        Log::debug('Fixing metadata for photo: '.$photo_id);
        if($photo) {
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

    public function checkProofsUploaded(): void
    {
        $this->check_proofs_uploaded = true;
    }

    public function processPendingImages(): void
    {
        $pathResolver = $this->pathResolver ?? app(PathResolver::class);
        $show_class = new ShowClass($this->show, $this->class, $pathResolver);
        $count = $show_class->processPendingImages();
        $this->flash_message = $count.' Images queued for import.';
        $this->check_proofs_uploaded = false;
    }

    public function processImage($image_path): void
    {
        $pathResolver = $this->pathResolver ?? app(PathResolver::class);
        $show_class = new ShowClass($this->show, $this->class, $pathResolver);
        $show_class->processImage($image_path);
        $this->flash_message = $image_path.' Processed.';
        $this->check_proofs_uploaded = false;
    }

    public function proofPendingImages(): void
    {
        $pathResolver = $this->pathResolver ?? app(PathResolver::class);
        $show_class = new ShowClass($this->show, $this->class, $pathResolver);
        $count = $show_class->proofPendingImages();
        $this->flash_message = $count.' Images proofed.';
        $this->check_proofs_uploaded = false;
    }

    public function proofImage($image_path): void
    {
        ini_set('memory_limit', '4096M');

        // Get PathResolver instance for this request
        $pathResolver = $this->pathResolver ?? app(PathResolver::class);

        // Get fresh paths based on the current PathResolver instance
        $proofs_path = $pathResolver->getProofsPath($this->show, $this->class);
        $web_images_path = $pathResolver->getWebImagesPath($this->show, $this->class);

        GenerateThumbnails::dispatch($image_path, $proofs_path)->onQueue('thumbnails');
        GenerateWebImage::dispatch($image_path, $web_images_path)->onQueue('thumbnails');
        $this->flash_message = $image_path.' Proofs queued.';
        $this->check_proofs_uploaded = false;
    }

    public function regenerateProofs(): void
    {
        $pathResolver = $this->pathResolver ?? app(PathResolver::class);
        $show_class = new ShowClass($this->show, $this->class, $pathResolver);
        $show_class->regenerateProofs();
        $this->flash_message = 'Queued';
        $this->check_proofs_uploaded = false;
    }

    public function uploadPendingProofsAndWebImages(): void
    {
        UploadProofs::dispatch($this->show, $this->class);

        $this->flash_message = 'Queued';

        $this->check_proofs_uploaded = false;
    }

    public function getImagesOfPath($path): array
    {
        $files = Utility::getFiles($path);
        $images = [];
        foreach ($files as $file) {
            // If it's a hidden file we'll ignore it
            $filename = explode('/', $file);
            $filename = array_pop($filename);
            if (str_starts_with($filename, '.')) {
                continue;
            }

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
