<?php

use App\Models\StorageProfile;
use App\Services\Storage\StorageProfileResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('maps legacy local profiles to the existing remote disks by content type', function () {
    $profile = StorageProfile::query()->findOrFail(StorageProfile::LEGACY_LOCAL_ID);
    $resolver = app(StorageProfileResolver::class);

    expect($resolver->diskFor($profile, StorageProfileResolver::CONTENT_PROOFS))->toBe('remote_proofs')
        ->and($resolver->diskFor($profile, StorageProfileResolver::CONTENT_WEB_IMAGES))->toBe('remote_web_images')
        ->and($resolver->diskFor($profile, StorageProfileResolver::CONTENT_HIGH_RES_IMAGES))->toBe('remote_highres_images');
});

it('registers a local profile disk lazily', function () {
    $root = storage_path('app/testing-profile-resolver');
    File::ensureDirectoryExists($root);

    $profile = StorageProfile::factory()->local()->create([
        'id' => 'cloud-local',
        'root' => $root,
    ]);

    $disk = app(StorageProfileResolver::class)->diskFor($profile);

    Storage::disk($disk)->put('proofs/SHOW1/101/file.jpg', 'bytes');

    expect($disk)->toBe('profile_cloud-local')
        ->and(Storage::disk($disk)->get('proofs/SHOW1/101/file.jpg'))->toBe('bytes');

    File::deleteDirectory($root);
});

it('rejects a local profile without a root path', function () {
    $profile = StorageProfile::factory()->create([
        'id' => 'missing-root',
        'driver' => 'local',
        'root' => null,
        'bucket' => null,
        'region' => null,
        'endpoint' => null,
        'use_path_style' => false,
    ]);

    app(StorageProfileResolver::class)->diskFor($profile);
})->throws(InvalidArgumentException::class);
