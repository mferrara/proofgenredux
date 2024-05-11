<?php

namespace App\Proofgen;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;

class Utility
{
    public static function getDirectoriesOfPath($path)
    {
        return Storage::disk('fullsize')->directories($path);
    }

    public static function getFiles($path)
    {
        return Storage::disk('fullsize')->files($path);
    }

    public static function getContentsOfPath($path, $recursive = false, $storage_disk = 'fullsize')
    {
        $contents = Storage::disk('fullsize')->listContents($path, $recursive);

        $directories = [];
        $images = [];
        foreach ($contents as $key => $object) {
            /** @var FileAttributes $object */
            switch($object->type()) {
                case 'dir':
                    $directories[] = $object;
                    break;
                case 'file':

                    if ($object->path() == 'errors') {
                        break;
                    }

                    $contains = ['jpg', 'jpeg'];
                    // If the $object->path contains any of the strings in $contains, add it to the images array
                    foreach($contains as $ext)
                    {
                        if(str_contains(strtolower($object->path()), $ext)) {
                            $images[] = $object;
                        }
                    }
                    break;
            }
        }

        // If there's images, sort them by their timestamp
        if (count($images)) {
            // Sort images by timestamp
            $temp_images = $images;
            $images = [];
            foreach ($temp_images as $key => $row) {
                $images[$key] = $row->lastModified();
            }
            array_multisort($images, SORT_ASC, $temp_images);
            $images = $temp_images;
            unset($temp_images);
        }

        $flysystem = null;
        $contents = null;
        unset($flysystem);
        unset($contents);

        return [
            'directories' => $directories,
            'images' => $images,
        ];
    }
}
