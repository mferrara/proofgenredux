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

        return view('livewire.home-component')
            ->with('current_path_contents', $current_path_contents)
            ->with('current_path_directories', $current_path_directories)
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

