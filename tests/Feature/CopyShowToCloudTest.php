<?php

use App\Models\MigrationInventory;
use App\Models\Show;
use App\Models\StorageProfile;
use App\Services\Migration\CopyShowToCloud;
use App\Services\Storage\StorageProfileResolver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config([
        'testing.skip_file_operations' => true,
        'proofgen.thumbnails.small.suffix' => '_thm',
        'proofgen.thumbnails.large.suffix' => '_std',
    ]);

    Storage::fake('remote_proofs');
    $this->cloudRoot = storage_path('app/testing-migration-copy-cloud');
    File::deleteDirectory($this->cloudRoot);
    File::ensureDirectoryExists($this->cloudRoot);
});

afterEach(function () {
    if (isset($this->cloudRoot)) {
        File::deleteDirectory($this->cloudRoot);
    }
});

function migration_copy_seed_show_and_profile(string $root): array
{
    $profile = StorageProfile::factory()->local()->create([
        'id' => 'cloud',
        'root' => $root,
        'is_active' => true,
    ]);
    app(StorageProfileResolver::class)->diskFor($profile);

    Show::withoutEvents(fn () => Show::create([
        'id' => 'SHOW1',
        'name' => 'Show One',
        'storage_profile_id' => StorageProfile::LEGACY_LOCAL_ID,
    ]));

    return [Show::find('SHOW1'), $profile];
}

function migration_copy_inventory_row(string $proofNumber = 'SHOW1_00001', string $status = MigrationInventory::STATUS_DISCOVERED): MigrationInventory
{
    return MigrationInventory::create([
        'show_slug' => 'SHOW1',
        'class_number' => '101',
        'proof_number' => $proofNumber,
        'content_type' => MigrationInventory::CONTENT_PROOF_STD,
        'source_disk' => 'remote_proofs',
        'source_path' => 'SHOW1/101/'.$proofNumber.'_std.jpg',
        'source_size_bytes' => 3,
        'status' => $status,
    ]);
}

it('copies discovered rows through Laravel Storage and records target keys', function () {
    [$show, $profile] = migration_copy_seed_show_and_profile($this->cloudRoot);
    migration_copy_inventory_row();
    Storage::disk('remote_proofs')->put('SHOW1/101/SHOW1_00001_std.jpg', 'std');

    $stats = app(CopyShowToCloud::class)->copy($show, $profile);

    expect($stats)->toMatchArray(['copied' => 1, 'skipped' => 0, 'failed' => 0, 'missing_source' => 0])
        ->and(Storage::disk('profile_cloud')->get('proofs/SHOW1/101/SHOW1_00001_std.jpg'))->toBe('std');

    $row = MigrationInventory::first();
    expect($row->status)->toBe(MigrationInventory::STATUS_COPIED)
        ->and($row->target_disk)->toBe('profile_cloud')
        ->and($row->target_object_key)->toBe('proofs/SHOW1/101/SHOW1_00001_std.jpg');
});

it('skips an existing target when the size already matches', function () {
    [$show, $profile] = migration_copy_seed_show_and_profile($this->cloudRoot);
    migration_copy_inventory_row();
    Storage::disk('remote_proofs')->put('SHOW1/101/SHOW1_00001_std.jpg', 'std');
    Storage::disk('profile_cloud')->put('proofs/SHOW1/101/SHOW1_00001_std.jpg', 'std');

    $stats = app(CopyShowToCloud::class)->copy($show, $profile);

    expect($stats)->toMatchArray(['copied' => 0, 'skipped' => 1, 'failed' => 0, 'missing_source' => 0])
        ->and(MigrationInventory::first()->status)->toBe(MigrationInventory::STATUS_COPIED);
});

it('fails rows when an existing target has a different size', function () {
    [$show, $profile] = migration_copy_seed_show_and_profile($this->cloudRoot);
    migration_copy_inventory_row();
    Storage::disk('remote_proofs')->put('SHOW1/101/SHOW1_00001_std.jpg', 'std');
    Storage::disk('profile_cloud')->put('proofs/SHOW1/101/SHOW1_00001_std.jpg', 'different');

    $stats = app(CopyShowToCloud::class)->copy($show, $profile);

    expect($stats['failed'])->toBe(1)
        ->and(MigrationInventory::first()->status)->toBe(MigrationInventory::STATUS_FAILED)
        ->and(MigrationInventory::first()->error_message)->toContain('target_size_mismatch');
});

it('marks rows missing source when the legacy file is absent', function () {
    [$show, $profile] = migration_copy_seed_show_and_profile($this->cloudRoot);
    migration_copy_inventory_row();

    $stats = app(CopyShowToCloud::class)->copy($show, $profile);

    expect($stats['missing_source'])->toBe(1)
        ->and(MigrationInventory::first()->status)->toBe(MigrationInventory::STATUS_FAILED)
        ->and(MigrationInventory::first()->error_message)->toBe('missing_source');
});
