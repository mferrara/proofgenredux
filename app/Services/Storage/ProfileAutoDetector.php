<?php

namespace App\Services\Storage;

use App\Models\StorageProfile;
use Illuminate\Support\Facades\Log;
use JsonException;

class ProfileAutoDetector
{
    /**
     * @return array<int, StorageProfile>
     *
     * @throws JsonException
     */
    public function detectFromEnv(): array
    {
        $detected = [];

        foreach ($this->profilePrefixes() as $profileId => $envPrefix) {
            $config = $this->configForPrefix($envPrefix);
            $fingerprint = $this->fingerprint($config);

            $existing = StorageProfile::query()->where('fingerprint', $fingerprint)->first()
                ?? StorageProfile::query()->find($profileId);

            if ($existing) {
                if ($existing->fingerprint !== $fingerprint) {
                    Log::error('Storage profile fingerprint drift', [
                        'profile' => $profileId,
                        'old' => $existing->fingerprint,
                        'new' => $fingerprint,
                    ]);
                }

                $detected[] = $existing;

                continue;
            }

            $detected[] = StorageProfile::query()->create([
                'id' => $profileId,
                'label' => $this->envValue("{$envPrefix}_LABEL", $profileId),
                'driver' => $config['driver'],
                'bucket' => $config['bucket'],
                'region' => $config['region'],
                'endpoint' => $config['endpoint'],
                'use_path_style' => $config['use_path_style'],
                'root' => $config['root'],
                'fingerprint' => $fingerprint,
                'is_active' => false,
                'is_writable' => true,
            ]);
        }

        return $detected;
    }

    /**
     * @throws JsonException
     */
    public function fingerprint(array $config): string
    {
        $config = $this->canonicalConfig($config);
        ksort($config);

        return hash('sha256', json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function configForProfileId(string $profileId): array
    {
        $envPrefix = 'PROFILE_'.strtoupper(str_replace('-', '_', $profileId));

        return $this->configForPrefix($envPrefix);
    }

    public function hasIdentityConfigForProfileId(string $profileId): bool
    {
        $envPrefix = 'PROFILE_'.strtoupper(str_replace('-', '_', $profileId));

        return $this->envValue("{$envPrefix}_KEY") !== null
            || $this->envValue("{$envPrefix}_ROOT") !== null
            || $this->envValue("{$envPrefix}_BUCKET") !== null;
    }

    private function configForPrefix(string $envPrefix): array
    {
        return $this->canonicalConfig([
            'driver' => $this->envValue("{$envPrefix}_DRIVER", 's3'),
            'bucket' => $this->envValue("{$envPrefix}_BUCKET"),
            'region' => $this->envValue("{$envPrefix}_REGION"),
            'endpoint' => $this->envValue("{$envPrefix}_ENDPOINT"),
            'use_path_style' => $this->envBool("{$envPrefix}_USE_PATH_STYLE"),
            'root' => $this->envValue("{$envPrefix}_ROOT"),
        ]);
    }

    private function canonicalConfig(array $config): array
    {
        return [
            'driver' => strtolower((string) ($config['driver'] ?? 's3')),
            'bucket' => $this->nullableString($config['bucket'] ?? null),
            'region' => $this->nullableString($config['region'] ?? null),
            'endpoint' => $this->nullableString($config['endpoint'] ?? null),
            'use_path_style' => (bool) ($config['use_path_style'] ?? false),
            'root' => $this->nullableString($config['root'] ?? null),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, string>
     */
    private function profilePrefixes(): array
    {
        $keys = array_unique(array_merge(
            array_keys($_ENV),
            array_keys($_SERVER),
            array_keys(getenv() ?: []),
        ));

        $prefixes = [];

        foreach ($keys as $key) {
            if (! preg_match('/^PROFILE_(.+)_KEY$/', (string) $key, $matches)) {
                continue;
            }

            $profileId = strtolower(str_replace('_', '-', $matches[1]));
            $prefixes[$profileId] = 'PROFILE_'.$matches[1];
        }

        ksort($prefixes);

        return $prefixes;
    }

    private function envValue(string $key, mixed $default = null): mixed
    {
        $value = env($key);

        if ($value !== null && $value !== false && $value !== '') {
            return $value;
        }

        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return $value;
    }

    private function envBool(string $key): bool
    {
        $value = $this->envValue($key, false);

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
