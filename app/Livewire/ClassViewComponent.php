<?php

namespace App\Livewire;

use App\Proofgen\ShowClass;
use App\Proofgen\Utility;
use Livewire\Component;

class ClassViewComponent extends Component
{
    public string $show = '';
    public string $class = '';
    public string $working_path = '';
    public string $fullsize_base_path = '';
    public string $archive_base_path = '';
    public string $working_full_path = '';

    public function mount()
    {
        $this->fullsize_base_path = config('proofgen.fullsize_home_dir');
        $this->archive_base_path = config('proofgen.archive_home_dir');
        $this->working_path = $this->show.'/'.$this->class;
    }
    public function render()
    {
        $this->working_full_path = $this->fullsize_base_path . '/' . $this->working_path;

        $current_path_contents = Utility::getContentsOfPath($this->working_path, false);
        $current_path_directories = Utility::getDirectoriesOfPath($this->working_path);

        $show_class = new ShowClass($this->show, $this->class);
        $images_pending_processing = $show_class->getImagesPendingProcessing();

        return view('livewire.class-view-component')
            ->with('current_path_contents', $current_path_contents)
            ->with('current_path_directories', $current_path_directories)
            ->with('images_pending_processing', $images_pending_processing);
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

    public function humanReadableFilesize($bytes): string
    {
        if ($bytes > 0) {
            $base = floor(log($bytes) / log(1024));
            $units = array("B", "KB", "MB", "GB", "TB", "PB", "EB", "ZB", "YB"); //units of measurement
            return number_format(($bytes / pow(1024, floor($base))), 2) . " $units[$base]";
        } else return "0 bytes";
    }
}
