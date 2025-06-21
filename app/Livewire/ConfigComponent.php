<?php

namespace App\Livewire;

use App\Models\Configuration;
use App\Proofgen\Image;
use App\Services\SwiftCompatibilityService;
use App\Services\UpdateService;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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

    protected $rules = [
        // We'll build dynamic rules in the save() method
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
        $this->updateHorizonStatus();

        // Check for updates when Settings page loads
        $this->checkForUpdates();

        // Set initial load to false after mount completes
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
            // Create the configuration with the current PHP binary path
            Configuration::setConfig(
                'php_binary_path',
                '/Users/mikeferrara/Library/Application Support/Herd/bin/php',
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
        Log::info('Stopping Horizon from ConfigComponent');

        try {
            // Get the HorizonService
            $horizonService = app(\App\Services\HorizonService::class);

            // Stop Horizon
            if ($horizonService->stop()) {
                Flux::toast(
                    text: 'Horizon has been stopped successfully.',
                    heading: 'Horizon Stopped',
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
            $horizonService = app(\App\Services\HorizonService::class);

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
        // Get all configurations
        $allConfigurations = Configuration::getAll();

        // Group configurations by category
        $this->groupConfigurationsByCategory($allConfigurations);

        // Set human-readable category labels
        $this->setCategoryLabels();
    }

    private function initializeConfigValues(): void
    {
        // Initialize values for all configurations
        foreach ($this->configurationsByCategory as $category => $configs) {
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

    public function updatingConfigValues($value, $key): void
    {
        // This method is called when config values are being updated
        // We no longer save immediately - changes are only saved when the Save button is clicked

        // Just validate the value
        $configId = str_replace('configValues.', '', $key);
        $config = Configuration::find($configId);

        if (! $config) {
            $this->returnError('Configuration '.$key.' not found.');

            return;
        }

        // Skip validation here - we'll validate on save with appropriate rules
    }

    /**
     * Handle updates to temp thumbnail values for preview
     */
    public function updatingTempThumbnailValues($value, $key): void
    {
        Log::debug('updatingTempThumbnailValues called', ['key' => $key, 'value' => $value]);

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
        Log::debug('updatePreview called', $this->tempThumbnailValues);
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

    public function togglePreviewWatermark(): void
    {
        $this->previewWatermarkEnabled = ! $this->previewWatermarkEnabled;
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
        foreach ($this->configurationsByCategory as $category => $configs) {
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
            'sftp' => 'Server (SFTP)',
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
        foreach ($this->configurationsByCategory as $category => $configs) {
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
        foreach ($this->configurationsByCategory as $category => $configs) {
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
                    if ($config->key === 'tone_mapping_percentile_low') {
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

        // Regenerate previews with new settings
        $this->initializeConfigValues();
        $this->initializeTempThumbnailValues();
        if ($this->sampleImagePath) {
            $this->generateThumbnailPreviews();
        }

        // Dispatch a success event
        Flux::toast(text: 'The settings have saved successfully.', heading: 'Settings saved', variant: 'success', position: 'top right');
        $this->dispatchUpdateEvent();
    }

    public function dispatchUpdateEvent(): void
    {
        Log::debug('dispatchUpdateEvent called');
        // Emit an event to notify other components
        $this->dispatch('config-updated')->to(AppStatusBar::class);
        Log::debug('AppStatusBar event dispatched');

        // Check if we should restart Horizon automatically
        if (config('proofgen.auto_restart_horizon', false)) {
            $this->scheduleHorizonRestart();
        }
    }

    /**
     * Schedule a Horizon restart
     * This method terminates Horizon directly, which will auto-restart if managed by a supervisor
     */
    public function scheduleHorizonRestart(): void
    {
        try {
            Log::info('Restarting Horizon due to configuration changes');

            // Get the HorizonService
            $horizonService = app(\App\Services\HorizonService::class);

            // Confirm Horizon is running before restarting
            if (! $horizonService->isRunning()) {
                Log::warning('Horizon is not running, cannot restart');
                Flux::toast(text: 'Horizon not running, no restart required.',
                    heading: 'Horizon Not Running',
                    variant: 'warning',
                    position: 'top right');

                return;
            }

            // Terminate Horizon directly using Artisan
            $exitCode = Artisan::call('horizon:terminate');
            
            if ($exitCode === 0) {
                Log::info('Horizon terminated successfully');
                
                // Wait a moment for processes to clean up
                sleep(2);
                
                // Start Horizon again
                $startResult = $horizonService->start();
                
                if ($startResult) {
                    Log::info('Horizon restarted successfully to apply configuration changes');
                    
                    // Show success message
                    Flux::toast(text: 'Horizon has been restarted to apply configuration changes.',
                        heading: 'Horizon Restarted',
                        variant: 'success',
                        position: 'top right');
                    
                    // Update status
                    $this->updateHorizonStatus();
                } else {
                    Log::error('Failed to start Horizon after termination');
                    
                    Flux::toast(text: 'Horizon was stopped but failed to restart. Please start it manually.',
                        heading: 'Horizon Restart Failed',
                        variant: 'danger',
                        position: 'top right');
                    
                    // Update status
                    $this->updateHorizonStatus();
                }
            } else {
                Log::error('Failed to terminate Horizon, exit code: ' . $exitCode);
                
                Flux::toast(text: 'Failed to restart Horizon. Please restart it manually.',
                    heading: 'Horizon Restart Failed',
                    variant: 'danger',
                    position: 'top right');
            }

        } catch (\Exception $e) {
            Log::error('Failed to restart Horizon: '.$e->getMessage());

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
        Log::info('Restarting Horizon directly from ConfigComponent');

        try {
            // Get the HorizonService
            $horizonService = app(\App\Services\HorizonService::class);

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
        Log::info('Starting Horizon from ConfigComponent');

        try {
            // Get the HorizonService
            $horizonService = app(\App\Services\HorizonService::class);

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
                    text: 'Failed to start Horizon. Check logs for details.',
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
        Log::debug('Initialized tempThumbnailValues', $this->tempThumbnailValues);
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
                    $largePreviewPathUnenhanced = $tempDir.'/large_preview_unenhanced_'.$timestamp.'.jpg';
                    $previewData = $this->createPreviewThumbnail($this->sampleImagePath, $largePreviewPath, 'thumbnails', 'large', true);
                    $this->largeThumbnailPreview = '/temp/thumbnail-preview/large_preview_'.$timestamp.'.jpg';
                    $this->largeThumbnailPreviewUnenhanced = '/temp/thumbnail-preview/large_preview_unenhanced_'.$timestamp.'.jpg';
                    $this->largeThumbnailInfo = $this->getFileInfo($largePreviewPath);
                    $this->largeThumbnailProcessingTime = $previewData['processing_time'];
                    $this->largeThumbnailInputSettings = $previewData['input_settings'];
                    $this->largeThumbnailEnhancementInfo = $previewData['enhancement'];
                    break;

                case 'small':
                    $smallPreviewPath = $tempDir.'/small_preview_'.$timestamp.'.jpg';
                    $smallPreviewPathUnenhanced = $tempDir.'/small_preview_unenhanced_'.$timestamp.'.jpg';
                    $previewData = $this->createPreviewThumbnail($this->sampleImagePath, $smallPreviewPath, 'thumbnails', 'small', true);
                    $this->smallThumbnailPreview = '/temp/thumbnail-preview/small_preview_'.$timestamp.'.jpg';
                    $this->smallThumbnailPreviewUnenhanced = '/temp/thumbnail-preview/small_preview_unenhanced_'.$timestamp.'.jpg';
                    $this->smallThumbnailInfo = $this->getFileInfo($smallPreviewPath);
                    $this->smallThumbnailProcessingTime = $previewData['processing_time'];
                    $this->smallThumbnailInputSettings = $previewData['input_settings'];
                    $this->smallThumbnailEnhancementInfo = $previewData['enhancement'];
                    break;

                case 'web':
                    $webPreviewPath = $tempDir.'/web_preview_'.$timestamp.'.jpg';
                    $webPreviewPathUnenhanced = $tempDir.'/web_preview_unenhanced_'.$timestamp.'.jpg';
                    $previewData = $this->createPreviewThumbnail($this->sampleImagePath, $webPreviewPath, 'web_images', null, true);
                    $this->webImagePreview = '/temp/thumbnail-preview/web_preview_'.$timestamp.'.jpg';
                    $this->webImagePreviewUnenhanced = '/temp/thumbnail-preview/web_preview_unenhanced_'.$timestamp.'.jpg';
                    $this->webImageInfo = $this->getFileInfo($webPreviewPath);
                    $this->webImageProcessingTime = $previewData['processing_time'];
                    $this->webImageInputSettings = $previewData['input_settings'];
                    $this->webImageEnhancementInfo = $previewData['enhancement'];
                    break;

                case 'highres':
                    $highresPreviewPath = $tempDir.'/highres_preview_'.$timestamp.'.jpg';
                    $highresPreviewPathUnenhanced = $tempDir.'/highres_preview_unenhanced_'.$timestamp.'.jpg';
                    $previewData = $this->createPreviewThumbnail($this->sampleImagePath, $highresPreviewPath, 'highres_images', null, true);
                    $this->highresImagePreview = '/temp/thumbnail-preview/highres_preview_'.$timestamp.'.jpg';
                    $this->highresImagePreviewUnenhanced = '/temp/thumbnail-preview/highres_preview_unenhanced_'.$timestamp.'.jpg';
                    $this->highresImageInfo = $this->getFileInfo($highresPreviewPath);
                    $this->highresImageProcessingTime = $previewData['processing_time'];
                    $this->highresImageInputSettings = $previewData['input_settings'];
                    $this->highresImageEnhancementInfo = $previewData['enhancement'];
                    break;
            }

        } catch (\Exception $e) {
            Log::error('Error generating thumbnail previews: '.$e->getMessage());
        } finally {
            $this->previewLoading = false;
        }
    }

    /**
     * Create a preview thumbnail with temporary settings
     */
    private function createPreviewThumbnail(string $sourcePath, string $destPath, string $type, ?string $size = null, bool $generateUnenhanced = false): array
    {
        Log::debug('createPreviewThumbnail: Reading source image', [
            'sourcePath' => $sourcePath,
            'destPath' => $destPath,
            'type' => $type,
            'size' => $size,
            'file_exists' => file_exists($sourcePath),
            'file_size' => file_exists($sourcePath) ? filesize($sourcePath) : 0,
        ]);

        $startTime = microtime(true);
        $manager = ImageManager::gd();
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
                $enhancementService = \App\Helpers\EnhancementServiceFactory::getService('preview');

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
            } catch (\Exception $e) {
                Log::error('Enhancement service failed in ConfigComponent preview: '.$e->getMessage());
                // Fall back to reading without enhancement
                $image = $manager->read($sourcePath);
                $enhancementInfo = [
                    'enabled' => false,
                    'error' => 'Enhancement failed: '.$e->getMessage(),
                ];
            }
        } else {
            $image = $manager->read($sourcePath);
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

        Log::debug("Creating {$type}".($size ? " {$size}" : '').' preview', ['width' => $width, 'height' => $height, 'quality' => $quality]);

        // Scale and save as JPEG with quality
        $image->scale($width, $height)
            ->toJpeg($quality)
            ->save($destPath);

        // Apply watermark if enabled and applicable
        if ($this->previewWatermarkEnabled) {
            if ($type === 'thumbnails' && $this->shouldApplyWatermark()) {
                $this->applyWatermarkToPreview($destPath, $size, $manager);
            } elseif ($type === 'web_images') {
                $this->applyWebImageWatermark($destPath, $manager);
            } elseif ($type === 'highres_images') {
                $this->applyHighresImageWatermark($destPath, $manager);
            }
        }

        // If enhancement is enabled and we need unenhanced version, create it too
        if ($generateUnenhanced && $enhancementEnabled) {
            // Generate unenhanced version
            $imageUnenhanced = $manager->read($sourcePath);
            $unenhancedPath = str_replace('_preview_', '_preview_unenhanced_', $destPath);

            $imageUnenhanced->scale($width, $height)
                ->toJpeg($quality)
                ->save($unenhancedPath);

            // Apply watermark to unenhanced version too
            if ($this->previewWatermarkEnabled) {
                if ($type === 'thumbnails' && $this->shouldApplyWatermark()) {
                    $this->applyWatermarkToPreview($unenhancedPath, $size, $manager);
                } elseif ($type === 'web_images') {
                    $this->applyWebImageWatermark($unenhancedPath, $manager);
                } elseif ($type === 'highres_images') {
                    $this->applyHighresImageWatermark($unenhancedPath, $manager);
                }
            }
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
        $image = $manager->read($imagePath);

        // Get original filename for watermark text
        $originalFilename = pathinfo($this->sampleImagePath, PATHINFO_FILENAME);

        if ($size === 'small') {
            // Small thumbnail watermark
            $watermark = \App\Proofgen\Image::watermarkSmallProof($originalFilename);
            $image->place($watermark, 'bottom-left', 10, 10)->save();
            imagedestroy($watermark);
        } elseif ($size === 'large') {
            // Large thumbnail watermark
            if ($image->width() > $image->height()) {
                // Landscape orientation
                $text = 'Proof# '.$originalFilename.' - Illegal to use - Ferrara Photography';
                $watermark = \App\Proofgen\Image::watermarkLargeProof($text, $image->width());
                $image->place($watermark, 'center')->save();
                imagedestroy($watermark);
            } else {
                // Portrait orientation - two watermarks
                $watermark_top = \App\Proofgen\Image::watermarkLargeProof(
                    'Proof# '.$originalFilename.' - Proof# '.$originalFilename,
                    $image->width()
                );
                $watermark_bot = \App\Proofgen\Image::watermarkLargeProof(
                    'Illegal to use - Ferrara Photography',
                    $image->width()
                );

                $bottom_offset = round($image->height() * 0.1);

                $image->place($watermark_top, 'center')
                    ->place($watermark_bot, 'bottom', 0, $bottom_offset)
                    ->save();

                imagedestroy($watermark_top);
                imagedestroy($watermark_bot);
            }
        }
    }

    /**
     * Apply web image watermark to preview image
     */
    private function applyWebImageWatermark(string $imagePath, ImageManager $manager): void
    {
        $image = $manager->read($imagePath);
        $watermarkPath = storage_path('watermarks/web-image-watermark-2.png');

        if (! file_exists($watermarkPath)) {
            Log::warning('Web image watermark file not found: '.$watermarkPath);

            return;
        }

        $watermark = imagecreatefrompng($watermarkPath);

        // Determine average color of bottom portion
        $averageColor = \App\Proofgen\Image::determineAverageColor($imagePath);
        $darkness = \App\Proofgen\Image::determineWatermarkDarknessFromAverageColor(
            $averageColor[0],
            $averageColor[1],
            $averageColor[2]
        );

        // Invert watermark if background is light
        if ($darkness === 'light') {
            imagefilter($watermark, IMG_FILTER_NEGATE);
        }

        // Place watermark at bottom with 60px offset
        $image->place($watermark, 'bottom', 0, 60)->save();

        imagedestroy($watermark);
    }

    /**
     * Apply highres image watermark to preview image
     */
    private function applyHighresImageWatermark(string $imagePath, ImageManager $manager): void
    {
        // Highres images use the same watermark as web images
        $this->applyWebImageWatermark($imagePath, $manager);
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
    protected function updateHorizonStatus(): void
    {
        $horizonService = app(\App\Services\HorizonService::class);
        $this->isHorizonRunning = $horizonService->isRunning();
    }

    /**
     * Handle updates to config values
     */
    public function updatedConfigValues($value, $key)
    {
        $configId = $key;
        $config = Configuration::find($configId);
        
        // If enabling image enhancement, force a Swift check
        if ($config && $config->key === 'image_enhancement_enabled' && $value) {
            $service = app(SwiftCompatibilityService::class);
            $this->swiftCompatibility = $service->checkCompatibility(force: true);
        }
    }

    public function render()
    {
        // Get process info if Horizon is running
        $horizonProcessInfo = [];
        if ($this->isHorizonRunning) {
            $horizonService = app(\App\Services\HorizonService::class);
            $horizonProcessInfo = $horizonService->getProcessInfo();
        }

        return view('livewire.config-component', [
            'isHorizonRunning' => $this->isHorizonRunning,
            'horizonProcessInfo' => $horizonProcessInfo,
        ]);
    }
}
