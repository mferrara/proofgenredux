<?php

namespace App\Livewire;

use App\Helpers\EnhancementServiceFactory;
use App\Models\Configuration;
use App\Proofgen\Image;
use App\Services\CoreImageDaemonService;
use App\Services\FerraraphotoTargetVerifier;
use App\Services\HorizonService;
use App\Services\ImageDiskConfigurator;
use App\Services\NativeFilePickerService;
use App\Services\SampleImagesService;
use App\Services\SwiftCompatibilityService;
use App\Services\SwiftCompilationService;
use App\Services\UpdateService;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;
use Livewire\Component;

class ConfigComponent extends Component
{
    public array $configurationsByCategory = [];

    public array $categoryLabels = [];

    public array $configValues = [];

    public array $configSources = [];

    // Thumbnail preview properties
    public array $tempThumbnailValues = [];

    public ?string $sampleImagePath = null;

    public ?string $largeThumbnailPreview = null;

    public ?string $smallThumbnailPreview = null;

    public ?string $webImagePreview = null;

    public ?string $highresImagePreview = null;

    public ?array $largeThumbnailInfo = null;

    public ?array $smallThumbnailInfo = null;

    public ?array $webImageInfo = null;

    public ?array $highresImageInfo = null;

    public bool $previewLoading = false;

    public array $previewErrors = [];

    public bool $initialLoad = true;

    // Active tab for image preview
    public string $activeTab = 'large';

    // Watermark preview toggle
    public bool $previewWatermarkEnabled = true;

    // Unenhanced preview properties for comparison
    public ?string $largeThumbnailPreviewUnenhanced = null;

    public ?string $smallThumbnailPreviewUnenhanced = null;

    public ?string $webImagePreviewUnenhanced = null;

    public ?string $highresImagePreviewUnenhanced = null;

    // Processing time tracking for each preview type
    public ?float $largeThumbnailProcessingTime = null;

    public ?float $smallThumbnailProcessingTime = null;

    public ?float $webImageProcessingTime = null;

    public ?float $highresImageProcessingTime = null;

    // Swift compatibility status
    public array $swiftCompatibility = [];

    // Swift binaries status
    public array $swiftBinariesStatus = [];

    public bool $compilingSwiftBinaries = false;

    // Horizon status
    public bool $isHorizonRunning = false;

    // Input settings tracking
    public ?array $largeThumbnailInputSettings = null;

    public ?array $smallThumbnailInputSettings = null;

    public ?array $webImageInputSettings = null;

    public ?array $highresImageInputSettings = null;

    // Enhancement info tracking
    public ?array $largeThumbnailEnhancementInfo = null;

    public ?array $smallThumbnailEnhancementInfo = null;

    public ?array $webImageEnhancementInfo = null;

    public ?array $highresImageEnhancementInfo = null;

    // Update system properties
    public ?array $updateInfo = null;

    public bool $checkingForUpdates = false;

    public bool $performingUpdate = false;

    public array $updateSteps = [];

    // Website connector panel state
    public bool $connectorTestRunning = false;

    public ?bool $connectorTestResult = null;

    public string $connectorTestOutput = '';

    public array $connectorPathsFound = [];

    public string $connectorShowToCheck = '';

    public ?array $connectorShowStatus = null;

    protected $rules = [
        // We'll build dynamic rules in the save() method
    ];

    protected $listeners = [
        'check-for-updates' => 'checkForUpdates',
        'regenerate-previews' => 'generateThumbnailPreviews',
    ];

    protected $messages = [
        'configValues.*.integer' => 'This field must be a number.',
        'configValues.*.between' => 'Quality must be between 10 and 100.',
    ];

    public function mount(): void
    {
        // Ensure we have the auto_restart_horizon configuration
        $this->ensureHorizonConfig();

        $this->loadConfigurations();
        $this->initializeConfigValues();
        $this->initializeThumbnailPreview();
        $this->checkSwiftCompatibility();
        $this->checkSwiftBinariesStatus();
        $this->updateHorizonStatus();

        // Defer the update check (git fetch) so it doesn't block initial page render
        $this->dispatch('check-for-updates');

        $this->initialLoad = false;
    }

    /**
     * Ensure that we have the Horizon auto-restart configuration
     */
    private function ensureHorizonConfig(): void
    {
        // Check if we already have this configuration
        $config = Configuration::where('key', 'auto_restart_horizon')->first();

        if (! $config) {
            // Create the configuration if it doesn't exist
            Configuration::setConfig(
                'auto_restart_horizon',
                'false',
                'boolean',
                'system',
                'Auto-restart Horizon',
                'Automatically restart Horizon when configuration values are changed'
            );
        }

        // Check if we have the PHP binary path configuration
        $phpBinaryConfig = Configuration::where('key', 'php_binary_path')->first();

        if (! $phpBinaryConfig) {
            // Detect the current PHP binary path
            $phpBinary = $this->detectPhpBinary();

            // Create the configuration with the detected PHP binary path
            Configuration::setConfig(
                'php_binary_path',
                $phpBinary,
                'string',
                'system',
                'PHP Binary Path',
                'Full path to PHP binary for executing CLI commands'
            );
        }
    }

    /**
     * Stop Horizon gracefully
     */
    public function stopHorizon(): void
    {
        // Log::info('Stopping Horizon from ConfigComponent');

        try {
            // Get the HorizonService
            $horizonService = app(HorizonService::class);

            // Stop Horizon
            if ($horizonService->stop()) {
                Flux::toast(
                    text: 'Workers will stop after finishing their current jobs.',
                    heading: 'Stop Requested',
                    variant: 'success',
                    position: 'top right'
                );

                // Update status
                $this->updateHorizonStatus();
            } else {
                Flux::toast(
                    text: 'Failed to stop Horizon. Check logs for details.',
                    heading: 'Stop Failed',
                    variant: 'danger',
                    position: 'top right'
                );
            }
        } catch (\Exception $e) {
            Log::error('Error stopping Horizon: '.$e->getMessage());

            Flux::toast(
                text: 'Error stopping Horizon: '.$e->getMessage(),
                heading: 'Stop Failed',
                variant: 'danger',
                position: 'top right'
            );
        }
    }

    /**
     * Force kill Horizon processes
     * This should only be used when normal stop doesn't work
     */
    public function forceKillHorizon(): void
    {
        Log::warning('Force killing Horizon from ConfigComponent');

        try {
            // Get the HorizonService
            $horizonService = app(HorizonService::class);

            // Force kill Horizon
            if ($horizonService->forceKill()) {
                Flux::toast(
                    text: 'All Horizon processes have been forcefully terminated.',
                    heading: 'Horizon Force Killed',
                    variant: 'warning',
                    position: 'top right'
                );
            } else {
                Flux::toast(
                    text: 'Failed to kill all Horizon processes. Check logs for details.',
                    heading: 'Force Kill Failed',
                    variant: 'danger',
                    position: 'top right'
                );
            }
        } catch (\Exception $e) {
            Log::error('Error force killing Horizon: '.$e->getMessage());

            Flux::toast(
                text: 'Error force killing Horizon: '.$e->getMessage(),
                heading: 'Force Kill Failed',
                variant: 'danger',
                position: 'top right'
            );
        }
    }

    public function loadConfigurations(): void
    {
        $allConfigurations = Configuration::getAll();
        $this->groupConfigurationsByCategory($allConfigurations);
        $this->setCategoryLabels();
    }

    private function initializeConfigValues(): void
    {
        // Initialize values for all configurations
        foreach ($this->configurationsByCategory as $configs) {
            foreach ($configs as $config) {
                $this->configValues[$config->id] = Configuration::castValue($config->value, $config->type);

                // Determine the source of the configuration value
                $envValue = config('proofgen.'.$config->key);
                if ($envValue !== null) {
                    // If the database value and env value are different, it's overridden
                    $dbValue = Configuration::castValue($config->value, $config->type);

                    // Need to normalize types for comparison
                    if (is_string($envValue) && is_numeric($envValue)) {
                        $envValue = (is_int((float) $envValue)) ? (int) $envValue : (float) $envValue;
                    }
                    if (is_string($envValue) && in_array(strtolower($envValue), ['true', 'false'])) {
                        $envValue = filter_var($envValue, FILTER_VALIDATE_BOOLEAN);
                    }

                    if ($dbValue !== $envValue) {
                        $this->configSources[$config->id] = 'database_override';
                    } else {
                        $this->configSources[$config->id] = 'same_in_both';
                    }
                } else {
                    $this->configSources[$config->id] = 'database_only';
                }
            }
        }
    }

    public function updatingConfigValues(mixed $value, string $key): void
    {
        unset($value); // Livewire hook signature; saving + validation happen on Save click.
        $configId = str_replace('configValues.', '', $key);
        $config = Configuration::find($configId);

        if (! $config) {
            $this->returnError('Configuration '.$key.' not found.');
        }
    }

    /**
     * Open the native macOS Finder folder picker and write the chosen path into
     * the config value bound at $configId. The dialog opens to the current value
     * if it points at an existing directory.
     */
    /**
     * Test the configured website connector by listing the remote proofs root
     * directory. Same logic as the standalone /config/server page, embedded
     * here so operators don't have to leave Settings to verify their config.
     */
    public function testConnectorConnection(): void
    {
        $this->connectorTestRunning = true;
        $this->connectorTestOutput = '';
        $this->connectorPathsFound = [];
        $this->connectorTestResult = null;

        try {
            $listing = Storage::disk('remote_proofs')->directories();
        } catch (\Throwable $e) {
            $this->connectorTestOutput = 'Connection failed: '.$e->getMessage();
            $this->connectorTestResult = false;
            $this->connectorTestRunning = false;

            return;
        }

        $this->connectorPathsFound = $listing;
        $this->connectorTestResult = true;
        $this->connectorTestOutput = count($listing) > 0
            ? 'Connection successful — '.count($listing).' show '.Str::plural('directory', count($listing)).' found at the proofs root.'
            : 'Connection successful, but no show directories were found at the proofs root.';
        $this->connectorTestRunning = false;
    }

    /**
     * Run the FerraraphotoTargetVerifier for a specific show id and surface
     * the per-disk status. Cached results from the verifier are bypassed —
     * operator-driven check should always reflect "right now."
     */
    public function checkConnectorShow(): void
    {
        $showId = trim($this->connectorShowToCheck);
        if ($showId === '') {
            $this->connectorShowStatus = null;

            return;
        }

        // verifyShow always hits the remote disks fresh — only verifyClassThrottled
        // has the 5-min cache — so operator-driven checks always reflect current state.
        $this->connectorShowStatus = app(FerraraphotoTargetVerifier::class)->verifyShow($showId);
    }

    public function pickFolderForConfig(int $configId): void
    {
        $config = Configuration::find($configId);
        if (! $config) {
            return;
        }
        $current = (string) ($this->configValues[$configId] ?? '');
        try {
            $picked = app(NativeFilePickerService::class)->pickFolder(
                initialPath: $current !== '' && is_dir($current) ? $current : null,
                prompt: $config->label ? 'Select '.$config->label : null,
            );
        } catch (\Throwable $e) {
            Flux::toast(text: 'Folder picker failed: '.$e->getMessage(), heading: 'Picker error', variant: 'danger');

            return;
        }
        if ($picked !== null) {
            $this->configValues[$configId] = $picked;
        }
    }

    /**
     * Same as pickFolderForConfig but for files. Used by sftp.private_key,
     * php_binary_path, and any other type=path config whose value is a file
     * rather than a directory.
     */
    /**
     * Classify a `type=path` config as a file picker vs folder picker so the UI
     * can render the right "Browse…" affordance. We use key-name heuristics
     * because the Configuration table only has a single `path` type. Anything
     * else falls through to "folder" (the safer default for our use cases).
     */
    public function pathPickerKindFor(string $key): string
    {
        if (str_contains($key, 'private_key') || str_ends_with($key, '_binary_path')) {
            return 'file';
        }

        return 'folder';
    }

    /**
     * Heuristic: does this `type=string` config key look like a path? Used to
     * widen + mono-font the input even though we can't offer a native picker
     * (these are remote ferraraphoto-server paths, not local).
     */
    public function isRemotePathLike(string $key): bool
    {
        return str_contains($key, 'sftp.') && (
            str_ends_with($key, '_path')
            || $key === 'sftp.path'
        );
    }

    public function pickFileForConfig(int $configId, ?array $ofType = null): void
    {
        $config = Configuration::find($configId);
        if (! $config) {
            return;
        }
        $current = (string) ($this->configValues[$configId] ?? '');
        try {
            $picked = app(NativeFilePickerService::class)->pickFile(
                initialPath: $current !== '' && is_file($current) ? dirname($current) : null,
                prompt: $config->label ? 'Select '.$config->label : null,
                ofType: $ofType,
            );
        } catch (\Throwable $e) {
            Flux::toast(text: 'File picker failed: '.$e->getMessage(), heading: 'Picker error', variant: 'danger');

            return;
        }
        if ($picked !== null) {
            $this->configValues[$configId] = $picked;
        }
    }

    /**
     * Handle updates to temp thumbnail values for preview
     */
    public function updatingTempThumbnailValues($value, $key): void
    {
        // Log::debug('updatingTempThumbnailValues called', ['key' => $key, 'value' => $value]);

        // Validate thumbnail values before updating
        if (str_contains($key, '.quality')) {
            if (! is_numeric($value) || $value < 10 || $value > 100) {
                return; // Don't update if invalid
            }
        } elseif (str_contains($key, '.width') || str_contains($key, '.height')) {
            if (! is_numeric($value) || $value < 1) {
                return; // Don't update if invalid
            }
        }

        // The value will be automatically updated by Livewire
        // Generate new previews after the update
        $this->generateThumbnailPreviews();
    }

    /**
     * Public method to update preview values and regenerate
     */
    public function updatePreview(): void
    {
        // Log::debug('updatePreview called', $this->tempThumbnailValues);
        $this->generateThumbnailPreviews();
    }

    /**
     * Update the active tab and generate preview for that tab
     */
    public function updateActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->generateThumbnailPreviews();
    }

    public function updatedPreviewWatermarkEnabled(): void
    {
        $this->generateThumbnailPreviews();
    }

    public function returnError(?string $message = null): void
    {
        if (! $message) {
            $message = 'An error occurred while saving the configuration.';
        }

        Flux::toast(text: $message, heading: 'Error', variant: 'danger', position: 'top right');
    }

    private function groupConfigurationsByCategory(Collection $configurations): void
    {
        // Initialize with "null" category first (for configurations without a category)
        $this->configurationsByCategory = ['null' => []];

        // Group configurations by category
        foreach ($configurations as $config) {
            $category = $config->category ?? 'null';

            if (! isset($this->configurationsByCategory[$category])) {
                $this->configurationsByCategory[$category] = [];
            }

            $this->configurationsByCategory[$category][] = $config;
        }

        // Sort configurations within each category by key
        foreach (array_keys($this->configurationsByCategory) as $category) {
            usort($this->configurationsByCategory[$category], function ($a, $b) {
                return strcmp($a->key, $b->key);
            });
        }

        // Sort categories alphabetically, but keep "null" category first
        $nullCategory = $this->configurationsByCategory['null'] ?? [];
        unset($this->configurationsByCategory['null']);

        ksort($this->configurationsByCategory);

        if (! empty($nullCategory)) {
            $this->configurationsByCategory = ['null' => $nullCategory] + $this->configurationsByCategory;
        }
    }

    private function setCategoryLabels(): void
    {
        $this->categoryLabels = [
            'null' => 'General',
            'proofs' => 'Proofs',
            'watermarks' => 'Watermarks',
            'thumbnails' => 'Thumbnails',
            'web_images' => 'Web Images',
            'highres_images' => 'High Resolution Images',
            'enhancement' => 'Image Enhancement',
            'sftp' => 'Legacy SFTP',
            'archive' => 'Archive',
            'system' => 'System Settings',
            // Add more category labels as needed
        ];
    }

    /**
     * Get the configuration ID for a specific key
     *
     * @param  string  $key  The configuration key
     * @return int|null The configuration ID
     */
    public function getConfigId(string $key): ?int
    {
        foreach ($this->configurationsByCategory as $configs) {
            foreach ($configs as $config) {
                if ($config->key === $key) {
                    return $config->id;
                }
            }
        }

        return null;
    }

    /**
     * Get a human-readable representation of a configuration value
     */
    public function getDisplayValue($value, $type): string
    {
        switch ($type) {
            case 'boolean':
                return $value ? 'Yes' : 'No';
            case 'array':
                return implode(', ', (array) $value);
            case 'json':
                return json_encode($value, JSON_PRETTY_PRINT);
            default:
                return (string) $value;
        }
    }

    public function save()
    {
        // Build dynamic validation rules based on config types
        $rules = [];
        foreach ($this->configurationsByCategory as $configs) {
            foreach ($configs as $config) {
                $rule = ['nullable'];

                if ($config->type === 'integer') {
                    $rule[] = 'integer';

                    // Special validation for quality fields
                    if (str_contains($config->key, '.quality')) {
                        $rule[] = 'between:10,100';
                    }
                    // For width/height fields, allow larger values
                    elseif (str_contains($config->key, '.width') || str_contains($config->key, '.height')) {
                        $rule[] = 'min:1';
                        $rule[] = 'max:9999';
                    }
                    // Enhancement grid size validation
                    elseif ($config->key === 'enhancement_clahe_grid_size') {
                        $rule[] = 'min:4';
                        $rule[] = 'max:16';
                    }
                } elseif ($config->type === 'float') {
                    $rule[] = 'numeric';

                    // Tone mapping percentile validation
                    if ($config->key === 'tone_mapping_highlight_amount') {
                        $rule[] = 'between:-100,0';
                    } elseif ($config->key === 'tone_mapping_percentile_low') {
                        $rule[] = 'min:0.0';
                        $rule[] = 'max:1.0';
                    } elseif ($config->key === 'tone_mapping_percentile_high') {
                        $rule[] = 'min:99.0';
                        $rule[] = 'max:100.0';
                    }
                    // CLAHE clip limit validation
                    elseif ($config->key === 'enhancement_clahe_clip_limit') {
                        $rule[] = 'min:1.0';
                        $rule[] = 'max:4.0';
                    }
                } elseif ($config->type === 'string') {
                    // For enhancement method, validate against allowed values
                    if ($config->key === 'image_enhancement_method') {
                        $rule[] = 'in:adjustable_auto_levels,advanced_tone_mapping';
                    } else {
                        // For other string fields, limit string length
                        $rule[] = 'max:250';
                    }
                } else {
                    // For other non-integer fields, limit string length
                    $rule[] = 'max:250';
                }

                $rules['configValues.'.$config->id] = $rule;
            }
        }

        $this->validate($rules);

        foreach ($this->configValues as $key => $value) {
            $config = Configuration::find($key);

            if ($config) {
                $current_value = Configuration::castValue($config->value, $config->type);
                $passed_value = Configuration::castValue($value, $config->type);
                if ($current_value === $passed_value) {
                    continue;
                }
                $config->value = Configuration::castValue($value, $config->type);
                if ($config->isDirty()) {
                    $config->save();
                }
            }
        }

        // Refresh configurations
        $this->loadConfigurations();
        $this->initializeConfigValues();
        $this->initializeTempThumbnailValues();
        Configuration::overrideApplicationConfig();
        app(ImageDiskConfigurator::class)->apply();

        Flux::toast(text: 'The settings have saved successfully.', heading: 'Settings saved', variant: 'success', position: 'top right');
        $this->dispatchUpdateEvent();

        // Defer preview regeneration so the save response returns immediately —
        // enhancement (CLAHE / tone-mapping / Swift) can take several seconds per preview.
        if ($this->sampleImagePath) {
            $this->dispatch('regenerate-previews');
        }
    }

    public function dispatchUpdateEvent(): void
    {
        $this->dispatch('config-updated')->to(AppStatusBar::class);

        if (config('proofgen.auto_restart_horizon', false)) {
            $this->scheduleHorizonRestart();
        }
    }

    /**
     * Queue a Horizon restart so the HTTP request returns immediately.
     * Delegates to HorizonService::scheduleRestart() which dispatches the
     * RestartHorizon job — that job uses the configured PHP binary path.
     */
    public function scheduleHorizonRestart(): void
    {
        try {
            $horizonService = app(HorizonService::class);

            if (! $horizonService->isRunning()) {
                Flux::toast(text: 'Horizon not running, no restart required.',
                    heading: 'Horizon Not Running',
                    variant: 'warning',
                    position: 'top right');

                return;
            }

            $horizonService->scheduleRestart();

            Flux::toast(text: 'Horizon is being restarted to apply configuration changes.',
                heading: 'Horizon Restarting',
                variant: 'info',
                position: 'top right');

        } catch (\Exception $e) {
            Log::error('Failed to schedule Horizon restart: '.$e->getMessage());

            Flux::toast(text: 'Failed to restart Horizon. Please restart it manually.',
                heading: 'Horizon Restart Failed',
                variant: 'danger',
                position: 'top right');
        }
    }

    /**
     * Restart Horizon programmatically directly from UI button
     * This is called when manually clicking the restart button
     * We use direct restart to avoid issues with stuck queue
     */
    public function restartHorizon(): void
    {
        // Log::info('Restarting Horizon directly from ConfigComponent');

        try {
            // Get the HorizonService
            $horizonService = app(HorizonService::class);

            // Use direct restart instead of queued job
            if ($horizonService->restartDirect()) {
                Flux::toast(
                    text: 'Horizon has been restarted successfully.',
                    heading: 'Horizon Restarted',
                    variant: 'success',
                    position: 'top right'
                );

                // Update status
                $this->updateHorizonStatus();
            } else {
                Flux::toast(
                    text: 'Failed to restart Horizon. Check logs for details.',
                    heading: 'Restart Failed',
                    variant: 'danger',
                    position: 'top right'
                );

                // Update status
                $this->updateHorizonStatus();
            }
        } catch (\Exception $e) {
            Log::error('Error restarting Horizon: '.$e->getMessage());

            Flux::toast(
                text: 'Error restarting Horizon: '.$e->getMessage(),
                heading: 'Restart Failed',
                variant: 'danger',
                position: 'top right'
            );
        }
    }

    /**
     * Start Horizon directly
     * This is used when Horizon is not running and needs to be started
     */
    public function startHorizon(): void
    {
        // Log::info('Starting Horizon from ConfigComponent');

        try {
            // Get the HorizonService
            $horizonService = app(HorizonService::class);

            // Start Horizon directly
            if ($horizonService->start()) {
                Flux::toast(
                    text: 'Horizon has been started successfully.',
                    heading: 'Horizon Started',
                    variant: 'success',
                    position: 'top right'
                );

                // Update status
                $this->updateHorizonStatus();
            } else {
                Flux::toast(
                    text: 'Horizon has not reported running yet. Status will refresh automatically; check the Horizon log if it stays stopped.',
                    heading: 'Start Failed',
                    variant: 'danger',
                    position: 'top right'
                );
            }
        } catch (\Exception $e) {
            Log::error('Error starting Horizon: '.$e->getMessage());

            Flux::toast(
                text: 'Error starting Horizon: '.$e->getMessage(),
                heading: 'Start Failed',
                variant: 'danger',
                position: 'top right'
            );
        }
    }

    public function cancel()
    {
        // Reload configurations to discard changes
        $this->loadConfigurations();
        // Reset the configValues array to the original values
        $this->initializeConfigValues();
        // Reset thumbnail preview values
        $this->initializeTempThumbnailValues();
        // Regenerate previews with original values
        if ($this->sampleImagePath) {
            $this->generateThumbnailPreviews();
        }
    }

    /**
     * Initialize thumbnail preview functionality
     */
    private function initializeThumbnailPreview(): void
    {
        // Find a sample image
        $this->findSampleImage();

        // Initialize temp values with current thumbnail settings
        $this->initializeTempThumbnailValues();

        // Generate initial previews if we have a sample image
        if ($this->sampleImagePath) {
            $this->generateThumbnailPreviews();
        }
    }

    /**
     * Initialize temporary thumbnail values from current config values
     */
    private function initializeTempThumbnailValues(): void
    {
        $thumbnailConfigs = $this->configurationsByCategory['thumbnails'] ?? [];
        $webImageConfigs = $this->configurationsByCategory['web_images'] ?? [];
        $highresImageConfigs = $this->configurationsByCategory['highres_images'] ?? [];

        // Initialize nested array structure
        $this->tempThumbnailValues = [
            'thumbnails' => [
                'large' => [],
                'small' => [],
            ],
            'web_images' => [],
            'highres_images' => [],
        ];

        // Process thumbnail configs
        foreach ($thumbnailConfigs as $config) {
            // Parse the key to create nested structure
            // e.g., "thumbnails.large.width" -> ['thumbnails']['large']['width']
            $parts = explode('.', $config->key);
            if (count($parts) === 3 && $parts[0] === 'thumbnails') {
                $size = $parts[1]; // 'large' or 'small'
                $property = $parts[2]; // 'width', 'height', 'quality', etc.
                $this->tempThumbnailValues['thumbnails'][$size][$property] = $this->configValues[$config->id];
            }
        }

        // Process web image configs
        foreach ($webImageConfigs as $config) {
            // e.g., "web_images.width" -> ['web_images']['width']
            $parts = explode('.', $config->key);
            if (count($parts) === 2 && $parts[0] === 'web_images') {
                $property = $parts[1]; // 'width', 'height', 'quality', etc.
                $this->tempThumbnailValues['web_images'][$property] = $this->configValues[$config->id];
            }
        }

        // Process highres image configs
        foreach ($highresImageConfigs as $config) {
            // e.g., "highres_images.width" -> ['highres_images']['width']
            $parts = explode('.', $config->key);
            if (count($parts) === 2 && $parts[0] === 'highres_images') {
                $property = $parts[1]; // 'width', 'height', 'quality', etc.
                $this->tempThumbnailValues['highres_images'][$property] = $this->configValues[$config->id];
            }
        }

        // Log for debugging
        // Log::debug('Initialized tempThumbnailValues', $this->tempThumbnailValues);
    }

    /**
     * Get thumbnail values mapped by key for Alpine.js
     */
    public function getThumbnailValuesByKey(): array
    {
        $values = [];
        $thumbnailConfigs = $this->configurationsByCategory['thumbnails'] ?? [];

        foreach ($thumbnailConfigs as $config) {
            $values[$config->key] = $this->configValues[$config->id];
        }

        return $values;
    }

    /**
     * Find a suitable sample image for preview
     */
    private function findSampleImage(): void
    {
        // First try storage/sample_images
        $sampleImagesPath = storage_path('sample_images');

        if (File::exists($sampleImagesPath)) {
            $images = File::allFiles($sampleImagesPath);

            foreach ($images as $image) {
                if (in_array(strtolower($image->getExtension()), ['jpg', 'jpeg', 'png'])) {
                    $this->sampleImagePath = $image->getPathname();

                    return;
                }
            }
        }

        // If no sample images, try to find an image in FULLSIZE_HOME_DIR
        $fullsizeDir = config('proofgen.fullsize_home_dir');
        if ($fullsizeDir && Storage::disk('fullsize')->exists('/')) {
            try {
                // Look for any image that's not a thumbnail, limit search for performance
                $directories = Storage::disk('fullsize')->directories();
                $found = false;

                foreach ($directories as $dir) {
                    if ($found) {
                        break;
                    }

                    $files = Storage::disk('fullsize')->files($dir);

                    foreach ($files as $file) {
                        // Skip thumbnails (files with _std or _thm suffix)
                        if (preg_match('/_(?:std|thm)\.[^.]+$/', $file)) {
                            continue;
                        }

                        $extension = pathinfo($file, PATHINFO_EXTENSION);
                        if (in_array(strtolower($extension), ['jpg', 'jpeg', 'png'])) {
                            $this->sampleImagePath = Storage::disk('fullsize')->path($file);
                            $found = true;
                            break;
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Error searching for sample images: '.$e->getMessage());
            }
        }

        Log::warning('ConfigComponent: No sample image found in either sample_images directory or fullsize disk');
    }

    /**
     * Generate thumbnail previews with current temporary settings
     */
    public function generateThumbnailPreviews(): void
    {
        if (! $this->sampleImagePath) {
            return;
        }

        $this->previewLoading = true;
        unset($this->previewErrors[$this->activeTab]);

        try {
            // Create temp directory for previews
            $tempDir = storage_path('app/temp/thumbnail-previews');
            if (! File::exists($tempDir)) {
                File::makeDirectory($tempDir, 0755, true);
            }

            // Clean up old previews
            $this->cleanupOldPreviews($tempDir);

            // Generate previews with temporary config values
            $timestamp = now()->timestamp;

            // Only generate preview for the active tab
            switch ($this->activeTab) {
                case 'large':
                    $largePreviewPath = $tempDir.'/large_preview_'.$timestamp.'.jpg';
                    $previewData = $this->createPreviewThumbnail($this->sampleImagePath, $largePreviewPath, 'thumbnails', 'large', true);
                    $this->largeThumbnailPreview = '/temp/thumbnail-preview/large_preview_'.$timestamp.'.jpg';
                    $this->largeThumbnailPreviewUnenhanced = ($previewData['enhancement']['enabled'] ?? false) ? '/temp/thumbnail-preview/large_preview_unenhanced_'.$timestamp.'.jpg' : null;
                    $this->largeThumbnailInfo = $this->getFileInfo($largePreviewPath);
                    $this->largeThumbnailProcessingTime = $previewData['processing_time'];
                    $this->largeThumbnailInputSettings = $previewData['input_settings'];
                    $this->largeThumbnailEnhancementInfo = $previewData['enhancement'];
                    break;

                case 'small':
                    $smallPreviewPath = $tempDir.'/small_preview_'.$timestamp.'.jpg';
                    $previewData = $this->createPreviewThumbnail($this->sampleImagePath, $smallPreviewPath, 'thumbnails', 'small', true);
                    $this->smallThumbnailPreview = '/temp/thumbnail-preview/small_preview_'.$timestamp.'.jpg';
                    $this->smallThumbnailPreviewUnenhanced = ($previewData['enhancement']['enabled'] ?? false) ? '/temp/thumbnail-preview/small_preview_unenhanced_'.$timestamp.'.jpg' : null;
                    $this->smallThumbnailInfo = $this->getFileInfo($smallPreviewPath);
                    $this->smallThumbnailProcessingTime = $previewData['processing_time'];
                    $this->smallThumbnailInputSettings = $previewData['input_settings'];
                    $this->smallThumbnailEnhancementInfo = $previewData['enhancement'];
                    break;

                case 'web':
                    $webPreviewPath = $tempDir.'/web_preview_'.$timestamp.'.jpg';
                    $previewData = $this->createPreviewThumbnail($this->sampleImagePath, $webPreviewPath, 'web_images', null, true);
                    $this->webImagePreview = '/temp/thumbnail-preview/web_preview_'.$timestamp.'.jpg';
                    $this->webImagePreviewUnenhanced = ($previewData['enhancement']['enabled'] ?? false) ? '/temp/thumbnail-preview/web_preview_unenhanced_'.$timestamp.'.jpg' : null;
                    $this->webImageInfo = $this->getFileInfo($webPreviewPath);
                    $this->webImageProcessingTime = $previewData['processing_time'];
                    $this->webImageInputSettings = $previewData['input_settings'];
                    $this->webImageEnhancementInfo = $previewData['enhancement'];
                    break;

                case 'highres':
                    $highresPreviewPath = $tempDir.'/highres_preview_'.$timestamp.'.jpg';
                    $previewData = $this->createPreviewThumbnail($this->sampleImagePath, $highresPreviewPath, 'highres_images', null, true);
                    $this->highresImagePreview = '/temp/thumbnail-preview/highres_preview_'.$timestamp.'.jpg';
                    $this->highresImagePreviewUnenhanced = ($previewData['enhancement']['enabled'] ?? false) ? '/temp/thumbnail-preview/highres_preview_unenhanced_'.$timestamp.'.jpg' : null;
                    $this->highresImageInfo = $this->getFileInfo($highresPreviewPath);
                    $this->highresImageProcessingTime = $previewData['processing_time'];
                    $this->highresImageInputSettings = $previewData['input_settings'];
                    $this->highresImageEnhancementInfo = $previewData['enhancement'];
                    break;
            }

        } catch (\Throwable $e) {
            Log::error('Error generating thumbnail previews: '.$e->getMessage());
            $this->previewErrors[$this->activeTab] = $e->getMessage();
        } finally {
            $this->previewLoading = false;
        }
    }

    /**
     * Create a preview thumbnail with temporary settings
     */
    private function createPreviewThumbnail(string $sourcePath, string $destPath, string $type, ?string $size = null, bool $generateUnenhanced = false): array
    {
        // Log::debug('createPreviewThumbnail: Reading source image', [
        //     'sourcePath' => $sourcePath,
        //     'destPath' => $destPath,
        //     'type' => $type,
        //     'size' => $size,
        //     'file_exists' => file_exists($sourcePath),
        //     'file_size' => file_exists($sourcePath) ? filesize($sourcePath) : 0,
        // ]);

        $startTime = microtime(true);
        $manager = new ImageManager(GdDriver::class);
        $enhancementInfo = null;

        // Check if enhancement is enabled and should be applied to this image type
        $enhancementEnabled = false;
        $enhancementMethod = 'basic_auto_levels';

        // Get enhancement configuration values
        $enhancementEnabledId = $this->getConfigId('image_enhancement_enabled');
        $enhancementMethodId = $this->getConfigId('image_enhancement_method');

        if ($enhancementEnabledId && isset($this->configValues[$enhancementEnabledId]) && $this->configValues[$enhancementEnabledId]) {
            // Check if we should apply to this type
            if ($type === 'thumbnails') {
                $applyToProofsId = $this->getConfigId('enhancement_apply_to_proofs');
                $enhancementEnabled = $applyToProofsId && isset($this->configValues[$applyToProofsId]) && $this->configValues[$applyToProofsId];
            } elseif ($type === 'web_images') {
                $applyToWebId = $this->getConfigId('enhancement_apply_to_web');
                $enhancementEnabled = $applyToWebId && isset($this->configValues[$applyToWebId]) && $this->configValues[$applyToWebId];
            } elseif ($type === 'highres_images') {
                $applyToHighresId = $this->getConfigId('enhancement_apply_to_highres');
                $enhancementEnabled = $applyToHighresId && isset($this->configValues[$applyToHighresId]) && $this->configValues[$applyToHighresId];
            }

            if ($enhancementMethodId && isset($this->configValues[$enhancementMethodId])) {
                $enhancementMethod = $this->configValues[$enhancementMethodId];
            }
        }

        // Apply enhancement if enabled
        if ($enhancementEnabled) {
            try {
                $enhancementService = EnhancementServiceFactory::getService('preview');

                // Get enhancement parameters from config values
                // Use temporary values if we're in preview mode (not saved yet)
                $parameters = [];

                // Note: percentile parameters are now included in tone mapping params below

                // Advanced Tone Mapping parameters
                $toneMappingParams = [
                    'tone_mapping_percentile_low',
                    'tone_mapping_percentile_high',
                    'tone_mapping_shadow_amount',
                    'tone_mapping_highlight_amount',
                    'tone_mapping_shadow_radius',
                    'tone_mapping_midtone_gamma',
                ];

                foreach ($toneMappingParams as $param) {
                    $paramId = $this->getConfigId($param);
                    if ($paramId && isset($this->configValues[$paramId])) {
                        $parameters[$param] = $this->configValues[$paramId];
                    }
                }

                // Adjustable Auto-Levels parameters
                $autoLevelsParams = [
                    'auto_levels_target_brightness',
                    'auto_levels_contrast_threshold',
                    'auto_levels_contrast_boost',
                    'auto_levels_black_point',
                    'auto_levels_white_point',
                ];

                foreach ($autoLevelsParams as $param) {
                    $paramId = $this->getConfigId($param);
                    if ($paramId && isset($this->configValues[$paramId])) {
                        $parameters[$param] = $this->configValues[$paramId];
                    }
                }

                $image = $enhancementService->enhance($sourcePath, $enhancementMethod, $parameters);

                // Store enhancement info
                $enhancementInfo = [
                    'enabled' => true,
                    'method' => $enhancementMethod,
                    'method_label' => $this->getEnhancementMethodLabel($enhancementMethod),
                    'parameters' => $this->formatEnhancementParameters($enhancementMethod, $parameters),
                ];
            } catch (\Throwable $e) {
                Log::error('Enhancement service failed in ConfigComponent preview: '.$e->getMessage());
                // Fall back to reading without enhancement, and report the
                // failure so the UI never claims enhancement was applied.
                $image = $manager->decodePath($sourcePath);
                $enhancementEnabled = false;
                $enhancementInfo = [
                    'enabled' => false,
                    'error' => 'Enhancement failed: '.$e->getMessage(),
                ];
            }
        } else {
            $image = $manager->decodePath($sourcePath);
        }

        // Get the temporary values based on type
        if ($type === 'thumbnails' && $size) {
            // For thumbnails, use nested structure
            $width = (int) ($this->tempThumbnailValues['thumbnails'][$size]['width'] ?? config("proofgen.thumbnails.{$size}.width"));
            $height = (int) ($this->tempThumbnailValues['thumbnails'][$size]['height'] ?? config("proofgen.thumbnails.{$size}.height"));
            $quality = (int) ($this->tempThumbnailValues['thumbnails'][$size]['quality'] ?? config("proofgen.thumbnails.{$size}.quality"));
        } else {
            // For web_images and highres_images, use flat structure
            $width = (int) ($this->tempThumbnailValues[$type]['width'] ?? config("proofgen.{$type}.width"));
            $height = (int) ($this->tempThumbnailValues[$type]['height'] ?? config("proofgen.{$type}.height"));
            $quality = (int) ($this->tempThumbnailValues[$type]['quality'] ?? config("proofgen.{$type}.quality"));
        }

        // Log::debug("Creating {$type}".($size ? " {$size}" : '').' preview', ['width' => $width, 'height' => $height, 'quality' => $quality]);

        $image->scaleDown($width, $height);
        $this->savePreviewImage($image, $destPath, $type, $size, $quality, $manager);

        if ($generateUnenhanced && $enhancementEnabled) {
            $imageUnenhanced = $manager->decodePath($sourcePath)->scaleDown($width, $height);
            $unenhancedPath = str_replace('_preview_', '_preview_unenhanced_', $destPath);
            $this->savePreviewImage($imageUnenhanced, $unenhancedPath, $type, $size, $quality, $manager);
        }

        $processingTime = microtime(true) - $startTime;

        // Return the enhancement info and processing time
        return [
            'enhancement' => $enhancementInfo,
            'processing_time' => $processingTime,
            'input_settings' => [
                'width' => $width,
                'height' => $height,
                'quality' => $quality,
            ],
        ];
    }

    /**
     * Clean up old preview files
     */
    private function cleanupOldPreviews(string $tempDir): void
    {
        $files = File::glob($tempDir.'/*_preview_*.jpg');
        foreach ($files as $file) {
            // Delete files older than 1 hour
            if (File::lastModified($file) < now()->subHour()->timestamp) {
                File::delete($file);
            }
        }
    }

    /**
     * Get file information for a preview image
     */
    private function getFileInfo(string $path): array
    {
        if (! File::exists($path)) {
            return [];
        }

        $size = File::size($path);
        [$width, $height] = getimagesize($path);

        return [
            'size' => $this->formatBytes($size),
            'dimensions' => $width.' × '.$height.' px',
        ];
    }

    /**
     * Check if enhancement is enabled for the current tab
     */
    public function isEnhancementEnabledForCurrentTab(): bool
    {
        $enhancementEnabledId = $this->getConfigId('image_enhancement_enabled');
        if (! $enhancementEnabledId || ! isset($this->configValues[$enhancementEnabledId]) || ! $this->configValues[$enhancementEnabledId]) {
            return false;
        }

        switch ($this->activeTab) {
            case 'large':
            case 'small':
                $applyToProofsId = $this->getConfigId('enhancement_apply_to_proofs');

                return $applyToProofsId && isset($this->configValues[$applyToProofsId]) && $this->configValues[$applyToProofsId];

            case 'web':
                $applyToWebId = $this->getConfigId('enhancement_apply_to_web');

                return $applyToWebId && isset($this->configValues[$applyToWebId]) && $this->configValues[$applyToWebId];

            case 'highres':
                $applyToHighresId = $this->getConfigId('enhancement_apply_to_highres');

                return $applyToHighresId && isset($this->configValues[$applyToHighresId]) && $this->configValues[$applyToHighresId];

            default:
                return false;
        }
    }

    /**
     * Format bytes into human readable format
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision).' '.$units[$i];
    }

    /**
     * Check for available updates
     */
    public function checkForUpdates(): void
    {
        $this->checkingForUpdates = true;

        try {
            $updateService = new UpdateService;
            $this->updateInfo = $updateService->checkForUpdates();
        } catch (\Exception $e) {
            Log::error('Error checking for updates: '.$e->getMessage());
            $this->updateInfo = [
                'current_version' => 'Unknown',
                'latest_version' => 'Unknown',
                'update_available' => false,
                'error' => $e->getMessage(),
            ];
        } finally {
            $this->checkingForUpdates = false;
        }
    }

    /**
     * Perform application update
     */
    public function performUpdate(): void
    {
        $this->performingUpdate = true;
        $this->updateSteps = [];

        Flux::modal('update-progress')->show();

        try {
            $updateService = new UpdateService;
            $result = $updateService->performUpdate();

            $this->updateSteps = $result['steps'];

            if ($result['success']) {
                Flux::toast(
                    text: 'Application updated successfully! The page will reload in 5 seconds.',
                    heading: 'Update Complete',
                    variant: 'success',
                    position: 'top right'
                );

                // Reload the page after a delay to ensure all changes are loaded
                $this->dispatch('reload-page-delayed');
            } else {
                Flux::toast(
                    text: 'Update failed: '.($result['error'] ?? 'Unknown error'),
                    heading: 'Update Failed',
                    variant: 'danger',
                    position: 'top right'
                );

                if ($result['backup_dir']) {
                    Flux::modal('rollback-instructions')->show();
                }
            }

            // Refresh update info
            $this->checkForUpdates();

        } catch (\Exception $e) {
            Log::error('Error performing update: '.$e->getMessage());

            $this->updateSteps[] = 'Fatal error: '.$e->getMessage();

            Flux::toast(
                text: 'Fatal error during update: '.$e->getMessage(),
                heading: 'Update Failed',
                variant: 'danger',
                position: 'top right'
            );
        } finally {
            $this->performingUpdate = false;
        }
    }

    /**
     * Get list of available backups
     */
    public function getBackups(): array
    {
        try {
            $updateService = new UpdateService;

            return $updateService->getBackups();
        } catch (\Exception $e) {
            Log::error('Error getting backups: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Get human-readable label for enhancement method
     */
    private function getEnhancementMethodLabel(string $method): string
    {
        return match ($method) {
            'basic_auto_levels' => 'Basic Auto-Levels',
            'adjustable_auto_levels' => 'Adjustable Auto-Levels',
            'percentile_clipping' => 'Percentile Clipping',
            'advanced_tone_mapping' => 'Advanced Tone Mapping',
            default => $method
        };
    }

    /**
     * Format enhancement parameters for display
     */
    private function formatEnhancementParameters(string $method, array $parameters): string
    {
        return match ($method) {
            'percentile_clipping', 'advanced_tone_mapping' => sprintf('%.1f%%-%.1f%%'.
                    (($parameters['tone_mapping_shadow_amount'] ?? 0) != 0 || ($parameters['tone_mapping_highlight_amount'] ?? 0) != 0 ?
                        ', S:%.0f H:%.0f' : ''),
                $parameters['tone_mapping_percentile_low'] ?? 0.1,
                $parameters['tone_mapping_percentile_high'] ?? 99.9,
                $parameters['tone_mapping_shadow_amount'] ?? 0,
                $parameters['tone_mapping_highlight_amount'] ?? 0),
            'basic_auto_levels', 'adjustable_auto_levels' => sprintf('Target: %d'.
                    (($parameters['auto_levels_black_point'] ?? 0) > 0 || ($parameters['auto_levels_white_point'] ?? 100) < 100 ?
                        ', Clip: %.1f%%-%.1f%%' : ''),
                $parameters['auto_levels_target_brightness'] ?? 128,
                $parameters['auto_levels_black_point'] ?? 0,
                $parameters['auto_levels_white_point'] ?? 100),
            default => ''
        };
    }

    /**
     * Check if watermarks should be applied based on configuration
     */
    private function shouldApplyWatermark(): bool
    {
        $watermarkProofsId = $this->getConfigId('watermark_proofs');

        return $watermarkProofsId && isset($this->configValues[$watermarkProofsId]) && $this->configValues[$watermarkProofsId];
    }

    /**
     * Apply watermark to preview image
     */
    private function applyWatermarkToPreview(string $imagePath, string $size, ImageManager $manager): void
    {
        $image = $manager->decodePath($imagePath);

        // Get original filename for watermark text
        $originalFilename = pathinfo($this->sampleImagePath, PATHINFO_FILENAME);

        if ($size === 'small') {
            // Small thumbnail watermark
            $watermark = Image::watermarkSmallProof($originalFilename);
            $image->insert($watermark, x: 10, y: 10, alignment: 'bottom-left')->save(quality: Image::WATERMARKED_PROOF_QUALITY);
        } elseif ($size === 'large') {
            // Large thumbnail watermark
            if ($image->width() > $image->height()) {
                // Landscape orientation
                $text = 'Proof# '.$originalFilename.' - Illegal to use - Ferrara Photography';
                $watermark = Image::watermarkLargeProof($text, $image->width());
                $image->insert($watermark, alignment: 'center')->save(quality: Image::WATERMARKED_PROOF_QUALITY);
            } else {
                // Portrait orientation - two watermarks
                $watermark_top = Image::watermarkLargeProof(
                    'Proof# '.$originalFilename.' - Proof# '.$originalFilename,
                    $image->width()
                );
                $watermark_bot = Image::watermarkLargeProof(
                    'Illegal to use - Ferrara Photography',
                    $image->width()
                );

                $bottom_offset = round($image->height() * 0.1);

                $image->insert($watermark_top, alignment: 'center')
                    ->insert($watermark_bot, x: 0, y: (int) $bottom_offset, alignment: 'bottom')
                    ->save(quality: Image::WATERMARKED_PROOF_QUALITY);

            }
        }
    }

    /** Use the production paid-image writer; proofs retain their two quality passes. */
    private function savePreviewImage(\Intervention\Image\Image $image, string $path, string $type, ?string $size, int $quality, ImageManager $manager): void
    {
        if ($this->previewWatermarkEnabled && in_array($type, ['web_images', 'highres_images'], true)) {
            Image::saveWatermarkedProduct($image, $path, $quality, $type === 'highres_images' ? 'High resolution image' : 'Web image');

            return;
        }

        $image->save($path, quality: $quality);
        if ($this->previewWatermarkEnabled && $type === 'thumbnails' && $this->shouldApplyWatermark()) {
            $this->applyWatermarkToPreview($path, $size, $manager);
        }
    }

    /**
     * Check Swift compatibility for Core Image enhancement
     */
    protected function checkSwiftCompatibility(): void
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            $service = app(SwiftCompatibilityService::class);
            $this->swiftCompatibility = $service->checkCompatibility();
        }
    }

    /**
     * Update Horizon running status
     */
    public function updateHorizonStatus(): void
    {
        $horizonService = app(HorizonService::class);
        $this->isHorizonRunning = $horizonService->isRunning();
    }

    /**
     * Check Swift binaries status
     */
    protected function checkSwiftBinariesStatus(): void
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            $compilationService = app(SwiftCompilationService::class);
            $this->swiftBinariesStatus = $compilationService->checkBinariesStatus();
        }
    }

    /**
     * Compile Swift binaries
     */
    public function compileSwiftBinaries(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            Flux::toast(
                text: 'Swift binaries can only be compiled on macOS.',
                heading: 'Not Available',
                variant: 'warning',
                position: 'top right'
            );

            return;
        }

        $this->compilingSwiftBinaries = true;

        try {
            $compilationService = app(SwiftCompilationService::class);
            $results = $compilationService->compileAll();

            if ($results['success']) {
                Flux::toast(
                    text: 'All Swift binaries compiled successfully!',
                    heading: 'Compilation Complete',
                    variant: 'success',
                    position: 'top right'
                );

                // Check if daemon needs to be restarted
                $daemonService = app(CoreImageDaemonService::class);
                if ($daemonService->isCoreImageAvailable()) {
                    Flux::modal('swift-restart-daemon')->show();
                }
            } else {
                $errorMessage = 'Failed to compile some binaries.';
                if (! empty($results['errors'])) {
                    $errorMessage .= ' '.implode(' ', $results['errors']);
                }

                Flux::toast(
                    text: $errorMessage,
                    heading: 'Compilation Failed',
                    variant: 'danger',
                    position: 'top right'
                );
            }

            // Refresh binaries status
            $this->checkSwiftBinariesStatus();

        } catch (\Exception $e) {
            Log::error('Error compiling Swift binaries: '.$e->getMessage());

            Flux::toast(
                text: 'Error compiling Swift binaries: '.$e->getMessage(),
                heading: 'Compilation Failed',
                variant: 'danger',
                position: 'top right'
            );
        } finally {
            $this->compilingSwiftBinaries = false;
        }
    }

    /**
     * Restart Core Image daemon after binary compilation
     */
    public function restartCoreImageDaemon(): void
    {
        try {
            $daemonService = app(CoreImageDaemonService::class);

            // Stop the daemon if running
            if ($daemonService->isCoreImageAvailable()) {
                $daemonService->stopDaemon();
                sleep(1);
            }

            // Start the daemon
            if ($daemonService->startDaemon()) {
                sleep(2); // Wait for daemon to start

                if ($daemonService->isCoreImageAvailable()) {
                    Flux::toast(
                        text: 'Core Image daemon restarted successfully to use new binaries.',
                        heading: 'Daemon Restarted',
                        variant: 'success',
                        position: 'top right'
                    );
                } else {
                    throw new \Exception('Daemon failed to start properly');
                }
            } else {
                throw new \Exception('Failed to start daemon');
            }

        } catch (\Exception $e) {
            Log::error('Error restarting Core Image daemon: '.$e->getMessage());

            Flux::toast(
                text: 'Failed to restart Core Image daemon: '.$e->getMessage(),
                heading: 'Restart Failed',
                variant: 'danger',
                position: 'top right'
            );
        }
    }

    /**
     * Download sample images from the configured S3 bucket.
     *
     * Synchronous on purpose — this is a single-tenant local app and the
     * operator clicks the button, waits, and gets a toast.
     */
    public function downloadSampleImages(SampleImagesService $sampleImagesService): void
    {
        try {
            $count = $sampleImagesService->downloadSampleImages();

            // Refresh the sample image path so previews can pick up newly downloaded files.
            $this->findSampleImage();
            if ($this->sampleImagePath) {
                $this->generateThumbnailPreviews();
            }

            Flux::toast(
                text: "Downloaded {$count} sample image".($count === 1 ? '' : 's').'.',
                heading: 'Sample Images Downloaded',
                variant: 'success',
                position: 'top right'
            );
        } catch (\Exception $e) {
            Log::error('Error downloading sample images: '.$e->getMessage());

            Flux::toast(
                text: 'Failed to download sample images: '.$e->getMessage(),
                heading: 'Download Failed',
                variant: 'danger',
                position: 'top right'
            );
        }
    }

    /**
     * Handle updates to config values
     */
    public function updatedConfigValues($value, $key)
    {
        $configId = $key;
        $config = Configuration::find($configId);

        if ($config && $config->key === 'image_enhancement_enabled' && $value) {
            if (empty($this->swiftCompatibility)) {
                $service = app(SwiftCompatibilityService::class);
                $this->swiftCompatibility = $service->checkCompatibility();
            }
        }
    }

    /**
     * Detect the PHP binary path dynamically
     */
    private function detectPhpBinary(): string
    {
        // Try to get PHP binary from current process
        if (defined('PHP_BINARY') && file_exists(PHP_BINARY)) {
            return PHP_BINARY;
        }

        // Try to find PHP in common locations
        $commonPaths = [
            '/usr/bin/php',
            '/usr/local/bin/php',
            '/opt/homebrew/bin/php',
            '/Applications/MAMP/bin/php/php*/bin/php', // MAMP
            '/usr/local/php*/bin/php', // Custom installs
        ];

        // Check if we're running under Laravel Herd
        $herdPaths = [
            $_SERVER['HOME'].'/Library/Application Support/Herd/bin/php',
            '/Applications/Herd.app/Contents/Resources/valet/bin/php',
        ];

        // Check Herd paths first if running under Herd
        foreach ($herdPaths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        // Check common paths
        foreach ($commonPaths as $pattern) {
            $paths = glob($pattern);
            if ($paths) {
                foreach ($paths as $path) {
                    if (file_exists($path) && is_executable($path)) {
                        return $path;
                    }
                }
            }
        }

        // Try to find PHP using 'which' command
        $result = shell_exec('which php 2>/dev/null');
        if ($result) {
            $path = trim($result);
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        // Default fallback
        return 'php';
    }

    public function render()
    {
        // Get process info if Horizon is running
        $horizonProcessInfo = [];
        if ($this->isHorizonRunning) {
            $horizonService = app(HorizonService::class);
            $horizonProcessInfo = $horizonService->getProcessInfo();
        }

        return view('livewire.config-component', [
            'isHorizonRunning' => $this->isHorizonRunning,
            'horizonProcessInfo' => $horizonProcessInfo,
        ])->title('Settings - Proofgen');
    }
}
