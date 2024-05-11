<?php

namespace App\Proofgen;

class ShowClass
{
    protected string $show_folder = '';
    protected string $class_folder = '';
    protected string $fullsize_base_path = '';
    protected string $archive_base_path = '';

    public function __construct(string $show_folder, string $class_folder)
    {
        $this->show_folder = $show_folder;
        $this->class_folder = $class_folder;
        $this->fullsize_base_path = config('proofgen.fullsize_home_dir');
        $this->archive_base_path = config('proofgen.archive_home_dir');
    }

    public function getImagesPendingProcessing(): ?array
    {
        $contents = Utility::getContentsOfPath('/'.$this->show_folder.'/'.$this->class_folder, false);

        $images = null;
        if(isset($contents['images']))
            $images = $contents['images'];

        return $images;
    }
}
