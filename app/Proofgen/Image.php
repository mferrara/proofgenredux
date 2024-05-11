<?php

namespace App\Proofgen;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class Image
{
    public string $image_path = '';
    public string $show = '';
    public string $class = '';
    public bool $rename_files = true;

    public string $filename = '';

    public function __construct(string $image_path, bool $rename_files = true)
    {
        $this->image_path = $image_path;

        // Determine show and class from path
        $path_parts = explode('/', $image_path);
        $this->show = $path_parts[0];
        $this->class = $path_parts[1];
        $this->filename = end($path_parts);
        $this->rename_files = $rename_files;
    }

    public function processImage(string $proof_number, bool $debug = false): void
    {
        // First we'll get the image from the directory
        $image = Storage::disk('fullsize')->get($this->image_path);

        $original_filename = $this->filename;
        $path_to_original_copy = $this->show.'/'.$this->class.'/originals/'.$this->filename;

        // Next, we'll move the image to the original directory
        Storage::disk('fullsize')->put($path_to_original_copy, $image);
        if($debug) {
            Log::debug('Moved image to originals directory; '.$this->image_path);
        }
        // Next, we'll confirm that copied file exists
        $exists = Storage::disk('fullsize')->exists($path_to_original_copy);
        if($debug) {
            Log::debug('File exists in originals directory; '.$this->image_path);
        }

        if( ! $exists) {
            throw new \Exception('File not copied to originals directory; '.$this->image_path);
        }

        // If we're configured to rename files we'll handle that now
        if($this->rename_files) {
            // First, get the extension from the file
            $extension = strtolower(pathinfo($this->filename, PATHINFO_EXTENSION));
            // Next, generate the new filename
            $new_filename = $proof_number.'.'.$extension;
            $new_original_path = $this->show.'/'.$this->class.'/originals/'.$new_filename;
            // Next, rename the file we put in the originals path
            Storage::disk('fullsize')->move($path_to_original_copy, $new_original_path);
            if($debug) {
                Log::debug('Renamed file in originals directory from '.$original_filename.' to '.$new_filename);
            }
            // Now, rename the file in $this->image_path
            $new_path = $this->show.'/'.$this->class.'/'.$new_filename;
            Storage::disk('fullsize')->move($this->image_path, $new_path);
            if($debug) {
                Log::debug('Renamed file in processing directory from '.$original_filename.' to '.$new_filename);
            }
            $this->filename = $new_filename;
            if($debug) {
                Log::debug('Filename updated to '.$new_filename);
            }
            $this->image_path = $new_path;
            if($debug) {
                Log::debug('Image path updated to '.$new_path);
            }
        }

        // Next we'll copy this file to the archive directory
        $archive_path = $this->show.'/'.$this->class.'/'.$this->filename;

        // First, we'll see if it already exists in the archive from a previous failed run...
        $exists = Storage::disk('archive')->exists($archive_path);
        if($exists){
            if($debug) {
                Log::debug('File already exists in archive directory; '.$archive_path.' - Deleting...');
            }
            Storage::disk('archive')->delete($archive_path);
        }

        Storage::disk('archive')->put($archive_path, $image);
        if($debug) {
            Log::debug('Copied file to archive directory; '.$archive_path);
        }

        // Next we'll confirm this copy of the file
        $exists = Storage::disk('archive')->exists($archive_path);
        if( ! $exists) {
            throw new \Exception('File not copied to archive directory; Tried to write file to: '.$archive_path);
        }
        if($debug) {
            Log::debug('File exists in archive directory; '.$archive_path);
        }

        // Finally, we'll delete the original file
        Storage::disk('fullsize')->delete($this->image_path);
        if($debug) {
            Log::debug('Deleted original file; '.$this->image_path);
        }
        // Confirm the file is deleted
        $exists = Storage::disk('fullsize')->exists($this->image_path);
        if($exists) {
            throw new \Exception('File not deleted; '.$this->image_path);
        }
    }
}
