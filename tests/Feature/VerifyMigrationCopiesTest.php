<?php

use App\Models\MigrationInventory;
use App\Models\Show;
use App\Models\StorageProfile;
use App\Services\Migration\VerifyMigrationCopies;
use App\Services\Storage\StorageProfileResolver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['testing.skip_file_operations' => true]);

    Storage::fake('remote_proofs');
    $this->cloudRoot = storage_path('app/testing-migration-verify-cloud');
    File::deleteDirectory($this->cloudRoot);
    File::ensureDirectoryExists($this->cloudRoot);
});

afterEach(function () {
    if (isset($this->cloudRoot)) {
        File::deleteDirectory($this->cloudRoot);
    }
});

function migration_verify_seed_show_and_profile(string $root): Show
{
    StorageProfile::factory()->local()->create([
        'id' => 'cloud',
        'root' => $root,
        'is_active' => true,
    ]);
    app(StorageProfileResolver::class)->diskFor(StorageProfile::find('cloud'));

    Show::withoutEvents(fn () => Show::create([
        'id' => 'SHOW1',
        'name' => 'Show One',
        'storage_profile_id' => StorageProfile::LEGACY_LOCAL_ID,
    ]));

    return Show::find('SHOW1');
}

function migration_verify_row(): MigrationInventory
{
    return MigrationInventory::create([
        'show_slug' => 'SHOW1',
        'class_number' => '101',
        'proof_number' => 'SHOW1_00001',
        'content_type' => MigrationInventory::CONTENT_PROOF_STD,
        'source_disk' => 'remote_proofs',
        'source_path' => 'SHOW1/101/SHOW1_00001_std.jpg',
        'source_size_bytes' => 3,
        'target_disk' => 'profile_cloud',
        'target_object_key' => 'proofs/SHOW1/101/SHOW1_00001_std.jpg',
        'status' => MigrationInventory::STATUS_COPIED,
    ]);
}

it('verifies copied rows with cheap size checks', function () {
    $show = migration_verify_seed_show_and_profile($this->cloudRoot);
    migration_verify_row();
    Storage::disk('remote_proofs')->put('SHOW1/101/SHOW1_00001_std.jpg', 'std');
    Storage::disk('profile_cloud')->put('proofs/SHOW1/101/SHOW1_00001_std.jpg', 'std');

    $stats = app(VerifyMigrationCopies::class)->verify($show);

    $row = MigrationInventory::first();
    expect($stats)->toMatchArray(['verified' => 1, 'failed' => 0])
        ->and($row->status)->toBe(MigrationInventory::STATUS_VERIFIED)
        ->and($row->source_sha1)->toBeNull()
        ->and($row->target_sha1)->toBeNull();
});

it('populates sha1 values in thorough mode', function () {
    $show = migration_verify_seed_show_and_profile($this->cloudRoot);
    migration_verify_row();
    Storage::disk('remote_proofs')->put('SHOW1/101/SHOW1_00001_std.jpg', 'std');
    Storage::disk('profile_cloud')->put('proofs/SHOW1/101/SHOW1_00001_std.jpg', 'std');

    $stats = app(VerifyMigrationCopies::class)->verify($show, thorough: true);

    $row = MigrationInventory::first();
    expect($stats)->toMatchArray(['verified' => 1, 'failed' => 0])
        ->and($row->source_sha1)->toBe(sha1('std'))
        ->and($row->target_sha1)->toBe(sha1('std'));
});

it('fails copied rows when source and target sizes do not match', function () {
    $show = migration_verify_seed_show_and_profile($this->cloudRoot);
    migration_verify_row();
    Storage::disk('remote_proofs')->put('SHOW1/101/SHOW1_00001_std.jpg', 'std');
    Storage::disk('profile_cloud')->put('proofs/SHOW1/101/SHOW1_00001_std.jpg', 'different');

    $stats = app(VerifyMigrationCopies::class)->verify($show);

    expect($stats['size_mismatch'])->toBe(1)
        ->and($stats['failed'])->toBe(1)
        ->and(MigrationInventory::first()->status)->toBe(MigrationInventory::STATUS_FAILED);
});
