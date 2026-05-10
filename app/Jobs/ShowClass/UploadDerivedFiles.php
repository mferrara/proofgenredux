<?php

namespace App\Jobs\ShowClass;

use App\Models\Photo;
use App\Models\ShowClass;
use App\Services\PathResolver;
use App\Services\Storage\StorageProfileResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class UploadDerivedFiles implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public string $classId) {}

    public function handle(StorageProfileResolver $profiles, PathResolver $paths): void
    {
        $class = ShowClass::with(['show.storageProfile', 'photos'])->findOrFail($this->classId);
        $profile = $class->show->storageProfile;

        if (! $profile) {
            throw new RuntimeException('Show '.$class->show_id.' is not pinned to a storage profile.');
        }

        if ($profile->isLegacyLocal()) {
            $this->legacyUpload($class);

            return;
        }

        $sourceDisk = Storage::disk('fullsize');
        $targetDisk = Storage::disk($profiles->diskFor($profile));

        foreach ($class->photos as $photo) {
            $this->copyPhoto($class, $photo, $paths, $sourceDisk, $targetDisk);
        }
    }

    private function copyPhoto(ShowClass $class, Photo $photo, PathResolver $paths, FilesystemAdapter $sourceDisk, FilesystemAdapter $targetDisk): void
    {
        $uploadedAt = now();
        $patch = [];
        $sourceShow = $class->show->id;
        $targetShow = $class->show->ferraraphoto_slug;
        $filename = $photo->proof_number.'.jpg';

        $proofThmKey = $this->uploadIfExists(
            $paths,
            $sourceDisk,
            $targetDisk,
            $paths->getProofThumbnailPath($sourceShow, $class->name, $filename, (string) config('proofgen.thumbnails.small.suffix')),
            $paths->getProofThumbnailPath($targetShow, $class->name, $filename, (string) config('proofgen.thumbnails.small.suffix')),
        );
        if ($proofThmKey) {
            $patch['proof_thm_key'] = $proofThmKey;
        }

        $proofStdKey = $this->uploadIfExists(
            $paths,
            $sourceDisk,
            $targetDisk,
            $paths->getProofThumbnailPath($sourceShow, $class->name, $filename, (string) config('proofgen.thumbnails.large.suffix')),
            $paths->getProofThumbnailPath($targetShow, $class->name, $filename, (string) config('proofgen.thumbnails.large.suffix')),
        );
        if ($proofStdKey) {
            $patch['proof_std_key'] = $proofStdKey;
        }

        if ($proofThmKey && $proofStdKey) {
            $patch['proofs_uploaded_at'] = $uploadedAt;
        }

        $webImageKey = $this->uploadIfExists(
            $paths,
            $sourceDisk,
            $targetDisk,
            $paths->getWebImagePath($sourceShow, $class->name, $filename, (string) config('proofgen.web_images.suffix')),
            $paths->getWebImagePath($targetShow, $class->name, $filename, (string) config('proofgen.web_images.suffix')),
        );
        if ($webImageKey) {
            $patch['web_image_key'] = $webImageKey;
            $patch['web_image_uploaded_at'] = $uploadedAt;
        }

        $highResImageKey = $this->uploadIfExists(
            $paths,
            $sourceDisk,
            $targetDisk,
            $paths->getHighresImagePath($sourceShow, $class->name, $filename, (string) config('proofgen.highres_images.suffix')),
            $paths->getHighresImagePath($targetShow, $class->name, $filename, (string) config('proofgen.highres_images.suffix')),
        );
        if ($highResImageKey) {
            $patch['high_res_image_key'] = $highResImageKey;
            $patch['highres_image_uploaded_at'] = $uploadedAt;
        }

        if ($patch !== []) {
            $photo->update($patch);
        }
    }

    private function uploadIfExists(PathResolver $paths, FilesystemAdapter $sourceDisk, FilesystemAdapter $targetDisk, string $sourcePath, string $targetPath): ?string
    {
        $sourceKey = $paths->normalizePath($sourcePath);
        $targetKey = $paths->normalizePath($targetPath);

        if (! $sourceDisk->exists($sourceKey)) {
            return null;
        }

        $stream = $sourceDisk->readStream($sourceKey);
        if ($stream === false) {
            throw new RuntimeException('Could not read derived file '.$sourceKey.'.');
        }

        try {
            $written = $targetDisk->writeStream($targetKey, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($written === false) {
            throw new RuntimeException('Could not write derived file '.$targetKey.'.');
        }

        return $targetKey;
    }

    private function legacyUpload(ShowClass $class): void
    {
        if ($class->photosProofedNotUploaded()->exists()) {
            UploadProofs::dispatchSync($class->show_id, $class->name);
        }

        if ($class->photosWebImagedNotUploaded()->exists()) {
            UploadWebImages::dispatchSync($class->show_id, $class->name);
        }

        if ($class->photosHighresImagedNotUploaded()->exists()) {
            UploadHighresImages::dispatchSync($class->show_id, $class->name);
        }
    }
}
