<?php

namespace App\Livewire;

use App\Proofgen\Utility;
use Livewire\Component;

class HomeComponent extends Component
{
    public string $working_path = '';
    public string $fullsize_base_path = '';
    public string $archive_base_path = '';
    public string $working_full_path = '';

    protected $queryString = [
        'working_path' => ['except' => ''],
    ];

    public function mount()
    {
        $this->fullsize_base_path = config('proofgen.fullsize_home_dir');
        $this->archive_base_path = config('proofgen.archive_home_dir');
    }

    public function render()
    {
        $this->working_full_path = $this->fullsize_base_path . '/' . $this->working_path;

        $current_path_contents = $this->getContentsOfPath($this->working_path, false);
        $current_path_directories = $this->getDirectoriesOfPath($this->working_path);

        // If the working_path contains an initial directory (e.g. 'show1'), we want to show that as the base path
        $base_show_path = explode('/', $this->working_path);
        if (count($base_show_path) >= 1) {
            $base_show_path = $base_show_path[0];
        } else {
            $base_show_path = null;
        }

        // If the working_path contains an base path + something else (e.g. 'show1/class1'), we want to show that as the base path
        $base_class_path = explode('/', $this->working_path);
        if (count($base_class_path) >= 2) {
            $base_class_path = $base_class_path[1];
        } else {
            $base_class_path = null;
        }

        // If the working_path contains exactly 1 item we're in a show directory
        $show_directory = false;
        if ($this->working_path !== '' && count(explode('/', $this->working_path)) === 1) {
            $show_directory = true;
        }

        // If the working_path contains exactly 2 items we're in a class directory
        $class_directory = false;
        if (count(explode('/', $this->working_path)) === 2) {
            $class_directory = true;
        }

        // If we're in a class directory we'll check for the 'proofs' and 'originals' directories
        $proofs_path_exists = false;
        $originals_path_exists = false;
        if ($class_directory) {
            foreach($current_path_directories as $directory) {
                dump($directory);
                if (str_contains($directory, 'proofs')) {
                    $proofs_path_exists = true;
                }
                if (str_contains($directory, 'originals')) {
                    $originals_path_exists = true;
                }
            }
        }

        return view('livewire.home-component')
            ->with('current_path_contents', $current_path_contents)
            ->with('current_path_directories', $current_path_directories)
            ->with('base_show_path', $base_show_path)
            ->with('base_class_path', $base_class_path)
            ->with('show_directory', $show_directory)
            ->with('class_directory', $class_directory)
            ->with('proofs_path_exists', $proofs_path_exists)
            ->with('originals_path_exists', $originals_path_exists)
            ->title('Proofgen Home');
    }

    public function backDirectory()
    {
        $path_array = explode('/', $this->working_path);
        if (count($path_array) < 1)
            return;

        // Unset the last value
        unset($path_array[count($path_array) - 1]);

        $this->working_path = implode('/', $path_array);
    }

    public function getFilesOfPath($path): array
    {
        return Utility::getFiles($path);
    }

    public function getImagesOfPath($path): array
    {
        $files = Utility::getFiles($path);
        $images = [];
        foreach ($files as $file) {

            foreach(['jpg', 'jpeg'] as $ext) {
                if (str_contains(strtolower($file), $ext)) {
                    $images[] = $file;
                }
            }
        }
        return $images;
    }

    public function getDirectoriesOfPath($path): array
    {
        return Utility::getDirectoriesOfPath($path);
    }

    public function getContentsOfPath($path, bool $recursive = false): array
    {
        return Utility::getContentsOfPath($path, $recursive);
    }
}

