<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class ImageDiskConfigurator
{
    public function apply(): void
    {
        $this->applyLocalDiskRoot('fullsize', config('proofgen.fullsize_home_dir'));
        $this->applyLocalDiskRoot('archive', config('proofgen.archive_home_dir'));
    }

    private function applyLocalDiskRoot(string $disk, ?string $root): void
    {
        if ($root === null || trim($root) === '') {
            return;
        }

        config(["filesystems.disks.{$disk}.root" => $root]);
        Storage::forgetDisk($disk);
    }
}
