<?php

use App\Jobs\Ferraraphoto\EnsureFerraraphotoShow;
use App\Jobs\ShowClass\PushPhotoMetadata;
use App\Models\MigrationInventory;
use App\Models\Photo;
use App\Models\Show;
use App\Models\ShowClass;
use App\Models\StorageProfile;
use App\Services\Ferraraphoto\FerraraphotoApiClient;
use App\Services\Migration\ShowMigrationCutover;
use App\Services\Storage\StorageProfileHealthCheck;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    config(['testing.skip_file_operations' => true]);
});

function migration_cutover_seed_show(): Show
{
    Show::withoutEvents(fn () => Show::create([
        'id' => 'SHOW1',
        'name' => 'Show One',
        'ferraraphoto_show_slug' => 'remote-show',
        'storage_profile_id' => StorageProfile::LEGACY_LOCAL_ID,
    ]));
    ShowClass::withoutEvents(fn () => ShowClass::create([
        'id' => 'SHOW1_101',
        'show_id' => 'SHOW1',
        'name' => '101',
    ]));
    Photo::withoutEvents(fn () => Photo::create([
        'id' => 'SHOW1_101_SHOW1_00001',
        'show_class_id' => 'SHOW1_101',
        'proof_number' => 'SHOW1_00001',
        'file_type' => 'jpg',
        'sha1' => sha1('SHOW1_00001'),
    ]));

    return Show::find('SHOW1');
}

function migration_cutover_seed_cloud_profile(): StorageProfile
{
    return StorageProfile::factory()->local()->active()->create([
        'id' => 'cloud',
        'root' => storage_path('app/testing-cutover-cloud'),
    ]);
}

function migration_cutover_inventory(string $status = MigrationInventory::STATUS_VERIFIED): void
{
    foreach ([
        MigrationInventory::CONTENT_PROOF_THM => 'proofs/remote-show/101/SHOW1_00001_thm.jpg',
        MigrationInventory::CONTENT_PROOF_STD => 'proofs/remote-show/101/SHOW1_00001_std.jpg',
        MigrationInventory::CONTENT_WEB_IMAGE => 'web_images/remote-show/101/SHOW1_00001_web.jpg',
        MigrationInventory::CONTENT_HIGH_RES_IMAGE => 'highres_images/remote-show/101/SHOW1_00001_highres.jpg',
    ] as $contentType => $targetKey) {
        MigrationInventory::create([
            'show_slug' => 'remote-show',
            'class_number' => '101',
            'proof_number' => 'SHOW1_00001',
            'content_type' => $contentType,
            'source_disk' => $contentType === MigrationInventory::CONTENT_WEB_IMAGE ? 'remote_web_images' : 'remote_proofs',
            'source_path' => 'remote-show/101/'.basename($targetKey),
            'source_size_bytes' => 3,
            'target_disk' => 'profile_cloud',
            'target_object_key' => $targetKey,
            'status' => $status,
        ]);
    }
}

function migration_cutover_bind_preflight_mocks(StorageProfile $profile, array $remoteHealth = ['status' => 'ok']): FerraraphotoApiClient
{
    $health = Mockery::mock(StorageProfileHealthCheck::class);
    $health->shouldReceive('check')->with(Mockery::on(fn ($arg) => $arg instanceof StorageProfile && $arg->id === $profile->id))->andReturn([
        'status' => 'ok',
        'message' => null,
    ]);
    app()->instance(StorageProfileHealthCheck::class, $health);

    $api = Mockery::mock(FerraraphotoApiClient::class);
    $api->shouldReceive('profileHealth')->with($profile->id)->andReturn($remoteHealth);
    app()->instance(FerraraphotoApiClient::class, $api);

    return $api;
}

it('reports preflight failures before cutover', function () {
    $show = migration_cutover_seed_show();
    $profile = migration_cutover_seed_cloud_profile();
    migration_cutover_inventory(MigrationInventory::STATUS_COPIED);
    migration_cutover_bind_preflight_mocks($profile);

    $result = app(ShowMigrationCutover::class)->preflight($show);

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'][0])->toContain('not verified');
});

it('transactionally pins the show, writes object keys, dispatches API sync jobs, and polls verification', function () {
    Bus::fake();
    $show = migration_cutover_seed_show();
    $profile = migration_cutover_seed_cloud_profile();
    migration_cutover_inventory();
    $api = migration_cutover_bind_preflight_mocks($profile);
    $api->shouldReceive('readShow')->with('remote-show')->andReturn([
        'classes' => [
            ['class_number' => '101', 'photo_count' => 1],
        ],
    ]);

    $result = app(ShowMigrationCutover::class)->cutover($show);

    $photo = Photo::find('SHOW1_101_SHOW1_00001');
    expect($result)->toMatchArray(['profile_id' => 'cloud', 'photos_updated' => 1, 'jobs_dispatched' => 2])
        ->and($show->fresh()->storage_profile_id)->toBe('cloud')
        ->and($photo->fresh()->proof_thm_key)->toBe('proofs/remote-show/101/SHOW1_00001_thm.jpg')
        ->and($photo->fresh()->proof_std_key)->toBe('proofs/remote-show/101/SHOW1_00001_std.jpg')
        ->and($photo->fresh()->web_image_key)->toBe('web_images/remote-show/101/SHOW1_00001_web.jpg')
        ->and($photo->fresh()->high_res_image_key)->toBe('highres_images/remote-show/101/SHOW1_00001_highres.jpg');

    Bus::assertChained([
        EnsureFerraraphotoShow::class,
        PushPhotoMetadata::class,
    ]);

    expect(app(ShowMigrationCutover::class)->pollFerraraphotoVerification($show->fresh()))->toBe([
        'ok' => true,
        'local_photo_count' => 1,
        'remote_photo_count' => 1,
    ]);
});
