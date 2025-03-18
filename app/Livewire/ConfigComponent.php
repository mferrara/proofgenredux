<?php

namespace App\Livewire;

use Flux\Flux;
use Livewire\Component;
use App\Models\Configuration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ConfigComponent extends Component
{
    public array $configurationsByCategory = [];
    public array $categoryLabels = [];
    public array $configValues = [];
    public array $configSources = [];

    protected $rules = [
        'configValues.*' => 'nullable|max:250',
    ];

    public function mount(): void
    {
        // Ensure we have the auto_restart_horizon configuration
        $this->ensureHorizonConfig();

        $this->loadConfigurations();
        $this->initializeConfigValues();
    }

    /**
     * Ensure that we have the Horizon auto-restart configuration
     */
    private function ensureHorizonConfig(): void
    {
        // Check if we already have this configuration
        $config = Configuration::where('key', 'auto_restart_horizon')->first();

        if (!$config) {
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

        if (!$phpBinaryConfig) {
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
                $envValue = config('proofgen.' . $config->key);
                if ($envValue !== null) {
                    // If the database value and env value are different, it's overridden
                    $dbValue = Configuration::castValue($config->value, $config->type);

                    // Need to normalize types for comparison
                    if (is_string($envValue) && is_numeric($envValue)) {
                        $envValue = (is_int((float)$envValue)) ? (int)$envValue : (float)$envValue;
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
        // Log::debug('Updating config value', ['key' => $key, 'value' => $value]);

        // If the $key is an integer, it means it's an ID, if it's a string, it's a key
        // Find the configuration by key
        if (is_numeric($key)) {
            $config = Configuration::find($key);
        } else {
            $config = Configuration::where('key', $key)->first();
        }

        if (!$config) {

            $this->returnError('Configuration '.$key.' not found.');

            return;
        }
        // Validate the updated value
        $this->validateOnly($config->id, [
            'configValues.' . $config->id => 'nullable|string|max:255',
        ]);

        // Log::debug('Update passed validation');

        // Update the configuration value
        $config->value = Configuration::castValue($value, $config->type);
        $config->save();

        // Refresh configurations
        $this->loadConfigurations();
        // Set the updated value in the configValues array
        $this->configValues[$config->id] = Configuration::castValue($config->value, $config->type);

        // Dispatch a success event
        $this->dispatchUpdateEvent();
    }

    public function returnError(?string $message = null): void
    {
        if( !$message ) {
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

            if (!isset($this->configurationsByCategory[$category])) {
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

        if (!empty($nullCategory)) {
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
            'sftp' => 'Server (SFTP)',
            'archive' => 'Archive',
            'system' => 'System Settings',
            // Add more category labels as needed
        ];
    }

    /**
     * Get the configuration ID for a specific key
     *
     * @param string $key The configuration key
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
        $this->validate();

        foreach ($this->configValues as $key => $value) {
            $config = Configuration::find($key);

            if ($config) {
                $current_value = Configuration::castValue($config->value, $config->type);
                $passed_value = Configuration::castValue($value, $config->type);
                if($current_value === $passed_value ) {
                    continue;
                }
                $config->value = Configuration::castValue($value, $config->type);
                if($config->isDirty()) {
                    $config->save();
                }
            }
        }

        // Refresh configurations
        $this->loadConfigurations();

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
     * This method schedules a job to restart Horizon, avoiding HTTP request timeouts
     */
    public function scheduleHorizonRestart(): void
    {
        try {
            Log::info('Scheduling Horizon restart due to configuration changes');

            // Get the HorizonService
            $horizonService = app(\App\Services\HorizonService::class);

            // Confirm Horizon is running before scheduling restart
            if (!$horizonService->isRunning()) {
                Log::warning('Horizon is not running, cannot schedule restart');
                Flux::toast(text: 'Horizon not running, no restart required.',
                    heading: 'Horizon Not Running',
                    variant: 'warning',
                    position: 'top right');
                return;
            }

            // Schedule the restart
            $horizonService->scheduleRestart();

            // Show success message
            Flux::toast(text: 'Horizon restart has been scheduled to apply configuration changes.',
                heading: 'Horizon Restart Scheduled',
                variant: 'success',
                position: 'top right');

        } catch (\Exception $e) {
            Log::error('Failed to schedule Horizon restart: ' . $e->getMessage());

            Flux::toast(text: 'Failed to schedule Horizon restart. Please restart it manually.',
                heading: 'Horizon Restart Failed',
                variant: 'danger',
                position: 'top right');
        }
    }

    /**
     * Restart Horizon programmatically directly from UI button
     * This is called when manually clicking the restart button
     * We use the queue job approach to avoid HTTP timeouts
     */
    public function restartHorizon(): void
    {
        $this->scheduleHorizonRestart();
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
            } else {
                Flux::toast(
                    text: 'Failed to start Horizon. Check logs for details.',
                    heading: 'Start Failed',
                    variant: 'danger', 
                    position: 'top right'
                );
            }
        } catch (\Exception $e) {
            Log::error('Error starting Horizon: ' . $e->getMessage());
            
            Flux::toast(
                text: 'Error starting Horizon: ' . $e->getMessage(),
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
    }

    public function render()
    {
        // Pass the Horizon status to the view
        $horizonService = app(\App\Services\HorizonService::class);
        $isHorizonRunning = $horizonService->isRunning();
        
        return view('livewire.config-component', [
            'isHorizonRunning' => $isHorizonRunning
        ]);
    }
}
