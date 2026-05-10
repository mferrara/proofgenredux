<?php

use App\Livewire\StorageProfilesComponent;
use App\Models\StorageProfile;
use App\Services\Storage\StorageProfileHealthCheck;
use Livewire\Livewire;

if (! function_exists('proofgen_storage_component_env')) {
    function proofgen_storage_component_env(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

beforeEach(function () {
    $health = Mockery::mock(StorageProfileHealthCheck::class);
    $health->shouldReceive('check')->andReturn(['status' => 'ok', 'message' => null]);
    $this->app->instance(StorageProfileHealthCheck::class, $health);
});

afterEach(function () {
    foreach ([
        'PROFILE_CLOUD_KEY',
        'PROFILE_CLOUD_SECRET',
        'PROFILE_CLOUD_LABEL',
        'PROFILE_CLOUD_BUCKET',
        'PROFILE_CLOUD_REGION',
        'PROFILE_CLOUD_ENDPOINT',
    ] as $key) {
        proofgen_storage_component_env($key, null);
    }
});

it('renders storage profiles with the active legacy local seed', function () {
    StorageProfile::factory()->create(['id' => 'cloud', 'label' => 'Cloud Profile']);

    Livewire::test(StorageProfilesComponent::class)
        ->assertSee('Storage Profiles')
        ->assertSee('Legacy (production server filesystem via SFTP)')
        ->assertSee('Cloud Profile')
        ->assertSee('Active profile:');

    expect(StorageProfile::query()->find(StorageProfile::LEGACY_LOCAL_ID)->is_active)->toBeTrue();
});

it('refreshes storage profiles from env', function () {
    proofgen_storage_component_env('PROFILE_CLOUD_KEY', 'key');
    proofgen_storage_component_env('PROFILE_CLOUD_SECRET', 'secret');
    proofgen_storage_component_env('PROFILE_CLOUD_LABEL', 'Cloud Profile');
    proofgen_storage_component_env('PROFILE_CLOUD_BUCKET', 'proofs-cloud');
    proofgen_storage_component_env('PROFILE_CLOUD_REGION', 'us-east-1');
    proofgen_storage_component_env('PROFILE_CLOUD_ENDPOINT', 'https://s3.example.test');

    Livewire::test(StorageProfilesComponent::class)
        ->call('refreshFromEnv')
        ->assertDispatched('toast-show');

    expect(StorageProfile::query()->whereKey('cloud')->exists())->toBeTrue();
});

it('sets a writable profile active transactionally', function () {
    StorageProfile::factory()->create([
        'id' => 'cloud',
        'label' => 'Cloud Profile',
        'is_active' => false,
        'is_writable' => true,
    ]);

    Livewire::test(StorageProfilesComponent::class)
        ->call('setActive', 'cloud')
        ->assertDispatched('toast-show');

    expect(StorageProfile::query()->find('cloud')->is_active)->toBeTrue()
        ->and(StorageProfile::query()->find(StorageProfile::LEGACY_LOCAL_ID)->is_active)->toBeFalse();
});

it('toggles a non-active profile writable state after confirmation', function () {
    StorageProfile::factory()->create([
        'id' => 'cloud',
        'label' => 'Cloud Profile',
        'is_active' => false,
        'is_writable' => true,
    ]);

    Livewire::test(StorageProfilesComponent::class)
        ->call('confirmWritableToggle', 'cloud')
        ->assertSet('pendingWritableProfileId', 'cloud')
        ->call('toggleWritable')
        ->assertSet('pendingWritableProfileId', null);

    expect(StorageProfile::query()->find('cloud')->is_writable)->toBeFalse();
});
