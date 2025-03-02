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

    protected $rules = [
        'configValues.*' => 'nullable|max:250',
    ];

    public function mount(): void
    {
        $this->loadConfigurations();
        $this->initializeConfigValues();
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
            // Add more category labels as needed
        ];
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
        return view('livewire.config-component');
    }
}
