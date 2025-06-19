<?php

namespace App\Proofgen;

use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\PhpseclibV2\SftpAdapter;
use League\Flysystem\PhpseclibV2\SftpConnectionProvider;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;

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
        $contents = Storage::disk($storage_disk)->listContents($path, $recursive);

        $directories = [];
        $images = [];
        foreach ($contents as $key => $object) {
            /** @var FileAttributes $object */
            switch ($object->type()) {
                case 'dir':
                    $directories[] = $object;
                    break;
                case 'file':

                    if ($object->path() == 'errors') {
                        break;
                    }

                    // If it's a hidden file we'll skip it
                    $filename = $object->path();
                    $filename = explode('/', $filename);
                    $filename = array_pop($filename);
                    if (str_starts_with($filename, '.')) {
                        break;
                    }

                    $contains = ['jpg', 'jpeg'];
                    // If the $object->path contains any of the strings in $contains, add it to the images array
                    foreach ($contains as $ext) {
                        if (str_contains(strtolower($object->path()), $ext)) {
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
                $class_contents = Utility::getContentsOfPath($dir->path().'/originals');
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
                $num = $img->path();
                // remove extension and path and show prefix
                $ext = pathinfo($num, PATHINFO_EXTENSION);
                $num = str_replace('.'.$ext, '', $num);
                $num = explode('/', $num);
                $num = array_pop($num);
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

    public static function remoteFilesystem(string $root_path): Filesystem
    {
        return new Filesystem(new SftpAdapter(
            new SftpConnectionProvider(
                config('proofgen.sftp.host'), // host (required)
                config('proofgen.sftp.username'), // username (required)
                null, // password (optional, default: null) set to null if privateKey is used
                config('proofgen.sftp.private_key'), // private key (optional, default: null) can be used instead of password, set to null if password is set
                null, // passphrase (optional, default: null), set to null if privateKey is not used or has no passphrase
                config('proofgen.sftp.port'), // port (optional, default: 22)
                true, // use agent (optional, default: false)
                10, // timeout (optional, default: 10)
                4, // max tries (optional, default: 4)
                null, // host fingerprint (optional, default: null),
                null, // connectivity checker (must be an implementation of 'League\Flysystem\PhpseclibV2\ConnectivityChecker' to check if a connection can be established (optional, omit if you don't need some special handling for setting reliable connections)
            ),
            $root_path, // root path (required)
            PortableVisibilityConverter::fromArray([
                'file' => [
                    'public' => 0640,
                    'private' => 0604,
                ],
                'dir' => [
                    'public' => 0700,
                    'private' => 0700,
                ],
            ])
        ));
    }
}
