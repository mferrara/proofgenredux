<?php

namespace App\Livewire;

use App\Proofgen\Utility;
use Livewire\Component;

class ShowViewComponent extends Component
{
    public string $working_path = '';
    public string $show = '';
    public string $fullsize_base_path = '';
    public string $archive_base_path = '';
    public string $working_full_path = '';

    public function mount()
    {
        $this->fullsize_base_path = config('proofgen.fullsize_home_dir');
        $this->archive_base_path = config('proofgen.archive_home_dir');
        $this->working_path = $this->show;
    }

    public function render()
    {
        $this->working_full_path = $this->fullsize_base_path . '/' . $this->working_path;

        $current_path_contents = Utility::getContentsOfPath($this->working_path, false);
        $current_path_directories = Utility::getDirectoriesOfPath($this->working_path);

        return view('livewire.show-view-component')
            ->with('current_path_contents', $current_path_contents)
            ->with('current_path_directories', $current_path_directories);
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
}