<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class Configuration extends Model
{
    const CONFIG_PREFIX = 'proofgen.';

    const CONFIG_REQUIRES_RELOAD_CACHE_KEY = 'config_requires_reload';

    const CONFIGURATIONS_ALL_COLLECTION_KEY = 'configurations.all';

    const CONFIGURATIONS_CATEGORY_KEY = 'configurations.category';

    const CONFIGURATIONS_COMPILED_ARRAY_KEY = 'configurations.compiled';

    const CONFIGURATIONS_SINGLE_VALUE_KEY = 'configurations';

    const CACHE_TTL = 60 * 60; // 1 hour

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'key',
        'value',
        'type',
        'category',
        'label',
        'description',
        'is_private',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_private' => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();

        // Clear the cache when a configuration is created, updated...
        static::saved(function (Configuration $model) {
            // Log::debug('Config saved for key: ' . $model->key.' with value: ' . $model->value);
            $model->clearCaches();
            self::setReloadRequiredFlag();
        });

        // or deleted
        static::deleted(function (Configuration $model) {
            $model->clearCaches();
            self::setReloadRequiredFlag();
        });
    }

    public static function setReloadRequiredFlag(): void
    {
        // Log::debug('Setting reload required flag');
        Cache::put(self::CONFIG_REQUIRES_RELOAD_CACHE_KEY, true);
    }

    public function clearCaches(): void
    {
        // Log::debug('Clearing all caches for key: ' . $this->key);
        Cache::forget(self::CONFIGURATIONS_SINGLE_VALUE_KEY.'.'.$this->key);
        if ($this->category) {
            Cache::forget(self::CONFIGURATIONS_CATEGORY_KEY.'.'.$this->category);
        }
        Cache::forget(self::CONFIGURATIONS_ALL_COLLECTION_KEY);
        Cache::forget(self::CONFIGURATIONS_COMPILED_ARRAY_KEY);
    }

    public static function getCompiled()
    {
        return Cache::remember(self::CONFIGURATIONS_COMPILED_ARRAY_KEY, self::CACHE_TTL, function () {
            return self::compile();
        });
    }

    public static function checkUpdateRequired(): bool
    {
        // Log::debug('Checking update required flag');
        return Cache::has(self::CONFIG_REQUIRES_RELOAD_CACHE_KEY);
    }

    public static function overrideApplicationConfig(): void
    {
        if (self::checkUpdateRequired()) {
            // If the cache has been invalidated, if so, compile the configurations
            // Log::debug('Updating compiled app configuration');
            $configs = self::compile();
        } else {
            // Get the compiled configuration from the cache
            $configs = self::getCompiled();
        }

        // If we have _something_ and not just an empty array, we'll use that
        if ($configs && count($configs) > 0) {
            // Log::debug(print_r($configs, true));
            foreach ($configs as $config_key => $config_value) {
                $full_key = self::CONFIG_PREFIX.$config_key;
                // Just as a sanity check while building this feature, we'll load the _current_ value for this config key
                $current_value = config($full_key);
                if (! $current_value && $current_value !== false) {
                    // There is no current value for this key, we'll set it below
                    // Log::debug('No current value for key: '. $full_key.' found, setting it to: ' . $config_value);
                } else {
                    // Is the current value an int as a string?
                    if (is_string($current_value) && is_numeric($current_value)) {
                        $current_value = (int) $current_value;
                    }
                    // Is the current value a bool as a string?
                    if (is_string($current_value) && in_array(strtolower($current_value), ['true', 'false'])) {
                        $current_value = filter_var($current_value, FILTER_VALIDATE_BOOLEAN);
                    }
                    // If the current value is the same as the new value, skip it
                    if ($current_value === $config_value) {
                        // Log::debug('Config key: ' . $full_key . ' already set to the same value: ' . $config_value);
                        continue;
                    }
                }

                // Set configuration key-value pair in the config repository
                config()->set($full_key, $config_value);
            }
        }
    }

    public static function compile(): array
    {
        // Load all configurations into cache
        $config_array = self::generateArrayForCache();
        if (count($config_array) < 1) {
            return [];
        }

        Cache::put(self::CONFIGURATIONS_COMPILED_ARRAY_KEY, $config_array, self::CACHE_TTL);
        Cache::forget(self::CONFIG_REQUIRES_RELOAD_CACHE_KEY);

        return $config_array;
    }

    public static function generateArrayForCache(): array
    {
        // Purposely avoiding getAll() or remember() in order to get the latest values
        $configs = self::all();

        // If there are no configurations, return an empty array
        if (! $configs) {
            return [];
        }

        $configArray = [];
        foreach ($configs as $config) {
            $configArray[$config->key] = self::getConfig($config->key);
        }

        return $configArray;
    }

    /**
     * Get a configuration value by key
     */
    public static function getConfig(string $key, mixed $default = null): mixed
    {
        return Cache::remember(self::CONFIGURATIONS_SINGLE_VALUE_KEY.'.'.$key, self::CACHE_TTL, function () use ($key) {
            $config = self::where('key', $key)->first();

            if (! $config) {
                // Get from the config() helper if it exists
                if (config($key) !== null) {
                    return config($key);
                }
                throw new \Exception('Configuration key not found: '.$key);
                // TODO: Un-comment this when we're done testing things
                // return $default;
            }

            // Cast the value based on type
            return self::castValue($config->value, $config->type);
        });
    }

    /**
     * Set a configuration value
     */
    public static function setConfig(
        string $key,
        mixed $value,
        ?string $type = null,
        ?string $category = null,
        ?string $label = null,
        ?string $description = null,
        bool $is_private = false
    ): Configuration {
        $config = self::firstOrNew(['key' => $key]);

        $config->value = $value;

        if ($type) {
            $config->type = $type;
        }

        if ($category) {
            $config->category = $category;
        }

        if ($label) {
            $config->label = $label;
        }

        if ($description) {
            $config->description = $description;
        }

        $config->is_private = $is_private;

        $config->save();

        return $config;
    }

    /**
     * Get all configurations by category
     */
    public static function getByCategory(?string $category = null): Collection
    {
        if ($category) {
            return Cache::remember(self::CONFIGURATIONS_CATEGORY_KEY.'.'.$category, self::CACHE_TTL, function () use ($category) {
                return self::where('category', $category)->get();
            });
        }

        return self::getAll();
    }

    public static function getAll(): Collection
    {
        return Cache::remember(self::CONFIGURATIONS_ALL_COLLECTION_KEY, self::CACHE_TTL, function () {
            return self::all();
        });
    }

    /**
     * Cast the value based on its type
     */
    public static function castValue(string $value, string $type): mixed
    {
        switch ($type) {
            case 'boolean':
                // Handle common boolean string representations
                $value = strtolower(trim($value));
                if (in_array($value, ['true', '1', 'yes', 'y', 'on'])) {
                    return true;
                }
                if (in_array($value, ['false', '0', 'no', 'n', 'off'])) {
                    return false;
                }

                // Default PHP-style casting for other values
                return ($value) ? true : false;
            case 'integer':
                return (int) $value;
            case 'float':
                return (float) $value;
            case 'json':
                return json_decode($value, true);
            case 'array':
                return explode(',', $value);
            default:
                return $value;
        }
    }

    /**
     * Get the PHP binary path to use for commands
     *
     * @return string The PHP binary path
     */
    public static function getPhpBinary(): string
    {
        // Get from configuration if available
        try {
            $configuredPath = self::getConfig('php_binary_path', null);

            if ($configuredPath) {
                return $configuredPath;
            }
        } catch (\Exception $e) {
            // If the config doesn't exist yet, just continue with default
        }

        // Default to 'php' command which will use system PATH
        return 'php';
    }

    public static function updateOrCreate(
        array $attributes,
        array $values = []
    ): Configuration {
        $config = self::where($attributes)->first();

        if ($config) {
            $config->update($values);
        } else {
            $config = self::create(array_merge($attributes, $values));
        }

        return $config;
    }
}
