<?php

namespace App\Livewire;

use App\Models\Show;
use App\Proofgen\Utility;
use Flux\Flux;
use Livewire\Component;

class HomeComponent extends Component
{
    public string $working_path = '';
    public string $fullsize_base_path = '';
    public string $archive_base_path = '';
    public string $working_full_path = '';
    public string $newShowName = '';

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

        $top_level_directories = $this->getDirectoriesOfPath($this->working_path);

        $remove = ['proofs', 'web_images'];
        $top_level_directories = array_diff($top_level_directories, $remove);

        // Loop through the top level directories determining which are imported as Shows
        $shows = [];
        foreach($top_level_directories as $directory_path) {
            $show = Show::with('photos')->find($directory_path);
            if($show) {
                $shows[$directory_path] = $show;
            }
        }

        return view('livewire.home-component')
            ->with('shows', $shows)
            ->with('top_level_directories', $top_level_directories)
            ->title('Proofgen Home');
    }

    public function createShow(string $show_id = null): Show
    {
        // If called from the modal form submission, use the newShowName property
        if (empty($show_id) && !empty($this->newShowName)) {
            $show_id = $this->newShowName;
            // Close the modal after submission
            Flux::modal('create-show')->close();
        }

        // Validate show name existence
        if (empty($show_id)) {
            Flux::toast(
                text: 'Show name cannot be empty',
                heading: 'Error',
                variant: 'error',
                position: 'top right'
            );
            return new Show(); // Return empty show to avoid errors
        }

        // Validate show name to only allow alphanumeric characters, underscores and hyphens
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $show_id)) {
            Flux::toast(
                text: 'Show name can only contain letters, numbers, underscores and hyphens',
                heading: 'Error',
                variant: 'error',
                position: 'top right'
            );
            return new Show(); // Return empty show to avoid errors
        }

        // Check if show already exists
        $show = Show::find($show_id);
        if ($show) {
            Flux::toast(
                text: 'Show already exists',
                heading: 'Info',
                variant: 'warning',
                position: 'top right'
            );
            return $show;
        }

        // Create the directory if it doesn't exist
        $directory_path = rtrim($this->fullsize_base_path, '/') . '/' . $show_id;
        if (!is_dir($directory_path)) {
            mkdir($directory_path, 0755, true);
        }

        // Create a new show
        $show = new Show();
        $show->id = $show_id;
        $show->name = $show_id;
        $show->save();

        // Reset the form field
        $this->newShowName = '';

        Flux::toast(
            text: 'Show created successfully',
            heading: 'Success',
            variant: 'success',
            position: 'top right'
        );

        return $show;
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

