<?php

namespace Database\Factories;

use App\Models\StorageProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StorageProfile>
 */
class StorageProfileFactory extends Factory
{
    protected $model = StorageProfile::class;

    public function definition(): array
    {
        $id = strtolower($this->faker->unique()->slug(3));
        $bucket = $id.'-bucket';
        $config = [
            'driver' => 's3',
            'bucket' => $bucket,
            'region' => 'us-east-1',
            'endpoint' => 'https://s3.example.test',
            'use_path_style' => true,
            'root' => null,
        ];

        return [
            'id' => $id,
            'label' => Str::headline($id),
            'driver' => $config['driver'],
            'bucket' => $config['bucket'],
            'region' => $config['region'],
            'endpoint' => $config['endpoint'],
            'use_path_style' => $config['use_path_style'],
            'root' => $config['root'],
            'fingerprint' => $this->fingerprint($config),
            'is_active' => false,
            'is_writable' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    public function local(): static
    {
        return $this->state(function (array $attributes) {
            $root = storage_path('app/testing-profile-'.$attributes['id']);
            $config = [
                'driver' => 'local',
                'bucket' => null,
                'region' => null,
                'endpoint' => null,
                'use_path_style' => false,
                'root' => $root,
            ];

            return [
                ...$config,
                'fingerprint' => $this->fingerprint($config),
            ];
        });
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'is_active' => true,
            'is_writable' => true,
        ]);
    }

    public function readOnly(): static
    {
        return $this->state(fn () => [
            'is_writable' => false,
        ]);
    }

    private function fingerprint(array $config): string
    {
        ksort($config);

        return hash('sha256', json_encode($config, JSON_UNESCAPED_SLASHES));
    }
}
