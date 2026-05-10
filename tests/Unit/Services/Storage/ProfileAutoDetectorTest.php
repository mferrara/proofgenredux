<?php

use App\Models\StorageProfile;
use App\Services\Storage\ProfileAutoDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

if (! function_exists('proofgen_profile_env')) {
    function proofgen_profile_env(string $key, ?string $value): void
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

afterEach(function () {
    foreach ([
        'PROFILE_CLOUD_KEY',
        'PROFILE_CLOUD_SECRET',
        'PROFILE_CLOUD_LABEL',
        'PROFILE_CLOUD_DRIVER',
        'PROFILE_CLOUD_BUCKET',
        'PROFILE_CLOUD_REGION',
        'PROFILE_CLOUD_ENDPOINT',
        'PROFILE_CLOUD_USE_PATH_STYLE',
        'PROFILE_CLOUD_ROOT',
    ] as $key) {
        proofgen_profile_env($key, null);
    }
});

it('fingerprints canonical storage identity including path style', function () {
    $detector = new ProfileAutoDetector;

    $withoutPathStyle = $detector->fingerprint([
        'root' => null,
        'use_path_style' => false,
        'endpoint' => 'https://s3.example.test',
        'region' => 'us-east-1',
        'bucket' => 'proofs',
        'driver' => 's3',
    ]);

    $canonical = [
        'bucket' => 'proofs',
        'driver' => 's3',
        'endpoint' => 'https://s3.example.test',
        'region' => 'us-east-1',
        'root' => null,
        'use_path_style' => true,
    ];
    ksort($canonical);

    expect($detector->fingerprint($canonical))
        ->toBe(hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)))
        ->not->toBe($withoutPathStyle);
});

it('detects env profiles idempotently', function () {
    proofgen_profile_env('PROFILE_CLOUD_KEY', 'key');
    proofgen_profile_env('PROFILE_CLOUD_SECRET', 'secret');
    proofgen_profile_env('PROFILE_CLOUD_LABEL', 'Cloud Proofs');
    proofgen_profile_env('PROFILE_CLOUD_BUCKET', 'proofs-cloud');
    proofgen_profile_env('PROFILE_CLOUD_REGION', 'us-east-1');
    proofgen_profile_env('PROFILE_CLOUD_ENDPOINT', 'https://s3.example.test');
    proofgen_profile_env('PROFILE_CLOUD_USE_PATH_STYLE', 'true');

    $detector = new ProfileAutoDetector;
    $first = $detector->detectFromEnv();
    $second = $detector->detectFromEnv();

    $profile = StorageProfile::query()->findOrFail('cloud');

    expect($first)->toHaveCount(1)
        ->and($second)->toHaveCount(1)
        ->and(StorageProfile::query()->where('id', 'cloud')->count())->toBe(1)
        ->and($profile->label)->toBe('Cloud Proofs')
        ->and($profile->is_active)->toBeFalse()
        ->and($profile->is_writable)->toBeTrue()
        ->and($profile->use_path_style)->toBeTrue();
});

it('logs fingerprint drift without replacing the existing profile', function () {
    proofgen_profile_env('PROFILE_CLOUD_KEY', 'key');
    proofgen_profile_env('PROFILE_CLOUD_SECRET', 'secret');
    proofgen_profile_env('PROFILE_CLOUD_BUCKET', 'new-bucket');
    proofgen_profile_env('PROFILE_CLOUD_REGION', 'us-east-1');

    $profile = StorageProfile::factory()->create([
        'id' => 'cloud',
        'bucket' => 'old-bucket',
        'fingerprint' => 'old-fingerprint',
    ]);

    Log::spy();

    $detected = (new ProfileAutoDetector)->detectFromEnv();

    expect($detected)->toHaveCount(1)
        ->and(StorageProfile::query()->find('cloud')->fingerprint)->toBe('old-fingerprint');

    Log::shouldHaveReceived('error')
        ->with('Storage profile fingerprint drift', Mockery::on(fn (array $context) => $context['profile'] === $profile->id));
});
