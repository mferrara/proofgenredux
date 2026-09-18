<?php

namespace App\Services\Delivery;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Storage access to a delivery target, for the directory exists/mkdir checks
 * that surround an rsync run. Always derived from the same target rsync uses,
 * so the check and the transfer cannot point at different places.
 */
class DeliveryTargetDisks
{
    private const LOCAL_DISKS = [
        'proofs' => 'remote_proofs',
        'web_images' => 'remote_web_images',
        'highres_images' => 'remote_highres_images',
    ];

    /**
     * The disk holding a sync type's show directory and the path of that
     * directory (or one class folder inside it) relative to the disk.
     *
     * @return array{0: Filesystem, 1: string}
     */
    public function locate(DeliveryTarget $target, string $syncType, string $classFolder = ''): array
    {
        // Local settings keep the named disks, rooted at the configured base
        // paths (SFTP normally, plain local in dev mode) exactly as before.
        if (! $target->isFromGallery()) {
            return [Storage::disk(self::LOCAL_DISKS[$syncType]), trim($target->showSlug.'/'.$classFolder, '/')];
        }

        // A Gallery target gets a disk built from the target itself, rooted
        // one level above the show directory so the show folder is a path.
        $directory = $target->directory($syncType);

        return [
            $this->remoteDisk($target, dirname($directory)),
            trim(basename($directory).'/'.$classFolder, '/'),
        ];
    }

    public function ensureDirectory(DeliveryTarget $target, string $syncType, string $classFolder = ''): void
    {
        [$disk, $path] = $this->locate($target, $syncType, $classFolder);

        if (! $disk->exists($path)) {
            $disk->makeDirectory($path);
        }
    }

    protected function remoteDisk(DeliveryTarget $target, string $root): Filesystem
    {
        return Storage::build([
            'driver' => 'sftp',
            'host' => $target->host,
            'port' => $target->port,
            'username' => $target->username,
            'privateKey' => config('proofgen.sftp.private_key'),
            'root' => $root,
            'throw' => true,
        ]);
    }
}
