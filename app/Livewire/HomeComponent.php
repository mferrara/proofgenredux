<?php

namespace App\Livewire;

use App\Models\Show;
use App\Models\StorageProfile;
use App\Proofgen\Utility;
use App\Services\StorageUsageService;
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

    public bool $showMiscStorage = false;

    public function loadMiscStorage(): void
    {
        $this->showMiscStorage = true;
    }

    public function refreshMiscStorage(): void
    {
        $service = app(StorageUsageService::class);
        $service->sampleImagesUsage(forceRefresh: true);
        $service->backupsUsage(forceRefresh: true);
        $this->showMiscStorage = true;
    }

    public function render()
    {
        $this->working_full_path = $this->fullsize_base_path.'/'.$this->working_path;

        $top_level_directories = $this->getDirectoriesOfPath($this->working_path);

        // Hide internal tree folders (and the graveyard) from the show list.
        // Exact basename matches only, so legitimate underscore-prefixed show
        // directories are still preserved.
        $remove = ['proofs', 'web_images', 'highres_images', '_graveyard'];
        $top_level_directories = array_values(array_filter(
            $top_level_directories,
            fn ($directory) => ! in_array(basename($directory), $remove, true)
        ));

        // Loop through the top level directories determining which are imported as Shows
        $shows = [];
        foreach ($top_level_directories as $directory_path) {
            $show = Show::with('photos')->find($directory_path);
            if ($show) {
                $shows[$directory_path] = $show;
            }
        }

        $miscStorage = null;
        if ($this->showMiscStorage) {
            $service = app(StorageUsageService::class);
            $miscStorage = [
                'sample_images' => $service->sampleImagesUsage(),
                'backups' => $service->backupsUsage(),
            ];
        }

        return view('livewire.home-component')
            ->with('shows', $shows)
            ->with('top_level_directories', $top_level_directories)
            ->with('misc_storage', $miscStorage)
            ->with('migration_progress', $this->migrationProgress())
            ->title('Proofgen Home');
    }

    private function migrationProgress(): array
    {
        $shows = Show::query()->with('storageProfile')->get();
        $total = $shows->count();
        $migrated = $shows
            ->filter(fn (Show $show) => $show->storage_profile_id !== null && $show->storage_profile_id !== StorageProfile::LEGACY_LOCAL_ID)
            ->count();

        return [
            'total' => $total,
            'migrated' => $migrated,
            'percent' => $total > 0 ? min(100, (int) floor(($migrated / $total) * 100)) : 0,
            'by_profile' => $shows
                ->groupBy(fn (Show $show) => $show->storageProfile?->label ?? 'Unpinned')
                ->map(fn ($group) => $group->count())
                ->sortKeys()
                ->all(),
        ];
    }

    public function createShow(?string $show_id = null): Show
    {
        // If called from the modal form submission, use the newShowName property
        if (empty($show_id) && ! empty($this->newShowName)) {
            $show_id = $this->newShowName;
            // Close the modal after submission
            Flux::modal('create-show')->close();
        }

        // Validate show name existence
        if (empty($show_id)) {
            Flux::toast(
                text: 'Show name cannot be empty',
                heading: 'Error',
                variant: 'danger',
                position: 'top right'
            );

            return new Show; // Return empty show to avoid errors
        }

        // Validate show name to only allow alphanumeric characters, underscores and hyphens
        if (! preg_match('/^[A-Za-z0-9_\-]+$/', $show_id)) {
            Flux::toast(
                text: 'Show name can only contain letters, numbers, underscores and hyphens',
                heading: 'Error',
                variant: 'danger',
                position: 'top right'
            );

            return new Show; // Return empty show to avoid errors
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
        $directory_path = rtrim($this->fullsize_base_path, '/').'/'.$show_id;
        if (! is_dir($directory_path)) {
            mkdir($directory_path, 0755, true);
        }

        // Create a new show
        $show = new Show;
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
        if ($this->working_path === '') {
            return;
        }

        $path_array = explode('/', $this->working_path);
        array_pop($path_array);
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

            foreach (['jpg', 'jpeg'] as $ext) {
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
