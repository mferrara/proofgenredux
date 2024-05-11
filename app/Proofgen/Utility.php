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

    public static function generateProofNumbers(string $show, int $count): array
    {
        // Generate the path for this show
        $show_path = $show;
        // Get all the class folders
        $contents = Utility::getContentsOfPath($show_path);

        $images = [];
        if (count($contents['directories']) > 0) {
            // Cycle through the class folders
            foreach ($contents['directories'] as $dir) {
                // Get the images from the 'originals' path, which will have renamed images.
                $class_contents = Utility::getContentsOfPath($show_path.'/'.$dir['path'].'/originals');
                $class_images = $class_contents['images'];

                // If there's images in here, drop them in the main images array
                if (count($class_images) > 0) {
                    foreach ($class_images as $image) {
                        $images[] = $image;
                    }
                }

                $image = null;
                $class_contents = null;
                $class_images = null;
                unset($image);
                unset($class_contents);
                unset($class_images);
            }
        }

        $image_numbers = [];
        if (count($images) > 0) {
            // Now we've got an array of images, we need to find the highest proof number of them all
            foreach ($images as $img) {
                $num = $img['filename'];
                $num = str_replace(strtoupper($show).'_', '', $num);
                $image_numbers[] = $num;
            }
            rsort($image_numbers);
        }

        if (count($image_numbers) > 0) {
            $highest_number = $image_numbers[0];
        } else {
            $highest_number = 0;
        }

        if ($highest_number !== 0 && ! ctype_digit($highest_number)) {
            dd('Non-numeric proof number found, please remove the '.$highest_number.' file from the originals path.');
        }

        $contents = null;
        unset($contents);

        // Generate an array of proof numbers to use
        $return_count = $count + 4; // +4 just so we have some extras
        $proof_numbers = [];
        $proof_num = $highest_number + 1;
        while ($return_count > 0) {
            $proof_num = str_pad($proof_num, 5, '0', STR_PAD_LEFT);
            $proof_numbers[] = strtoupper($show).'_'.$proof_num;
            $proof_num++;
            $return_count--;
        }

        return $proof_numbers;
    }
}
