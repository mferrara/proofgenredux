<?php

namespace App\Livewire;

use App\Models\Configuration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class ConfigComponent extends Component
{
    public array $configurationsByCategory = [];
    public array $categoryLabels = [];
    public array $configValues = [];

    public bool $upload_proofs = false;

    protected $rules = [
        'configValues.*' => 'nullable|string|max:255',
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
                $this->configValues[$config->key] = Configuration::castValue($config->value, $config->type);
            }
        }
    }

    public function toggleBooleanConfigValue(string $key): void
    {
        $config = Configuration::where('key', $key)->first();

        if( ! $config ) {
            return;
        }

        $config->value = !$config->value;
        $config->save();

        $this->dispatch('config-updated', [
            'key' => $key,
            'value' => $config->value,
        ]);

        // Refresh configurations
        $this->loadConfigurations();
        // Set the updated value in the configValues array
        $this->configValues[$config->key] = Configuration::castValue($config->value, $config->type);
    }

    public function updateConfigValue(string $key, string $value): void
    {
        $config = Configuration::where('key', $key)->first();

        if (!$config) {
            return;
        }

        $config->value = Configuration::castValue($value, $config->type);
        $config->save();

        $this->dispatch('config-updated', [
            'key' => $key,
            'value' => $config->value,
        ]);

        // Refresh configurations
        $this->loadConfigurations();
        // Set the updated value in the configValues array
        $this->configValues[$config->key] = Configuration::castValue($config->value, $config->type);
    }

    public function updatingConfigValues($value, $key): void
    {
        Log::debug('Updating config value', [
            'key' => $key,
            'value' => $value,
        ]);
        // Validate the updated value
        $this->validateOnly($key, [
            'configValues.' . $key => 'nullable|string|max:255',
        ]);

        Log::debug('Update passed validation');

        // Update the configuration value
        $this->updateConfigValue($key, $value);
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

    public function render()
    {
        Log::debug('upload_proofs is ' . ($this->upload_proofs ? 'true' : 'false'));
        return view('livewire.config-component');
    }
}
