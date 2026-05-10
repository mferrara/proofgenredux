<?php

use App\Jobs\ShowClass\PushPhotoMetadata;
use App\Models\Photo;
use App\Models\Show;
use App\Models\ShowClass;
use App\Models\StorageProfile;
use App\Services\Ferraraphoto\FerraraphotoApiClient;
use Illuminate\Support\Collection;

function proofgen_seed_metadata_class(int $photoCount, string $profileId = 'cloud'): ShowClass
{
    if ($profileId !== StorageProfile::LEGACY_LOCAL_ID && ! StorageProfile::query()->find($profileId)) {
        StorageProfile::factory()->create(['id' => $profileId]);
    }

    Show::withoutEvents(fn () => Show::create([
        'id' => 'SHOW1',
        'name' => 'Show One',
        'storage_profile_id' => $profileId,
    ]));
    ShowClass::withoutEvents(fn () => ShowClass::create(['id' => 'SHOW1_101', 'show_id' => 'SHOW1', 'name' => '101']));

    Photo::withoutEvents(function () use ($photoCount) {
        for ($i = 1; $i <= $photoCount; $i++) {
            $proofNumber = sprintf('SHOW1_%05d', $i);
            Photo::create([
                'id' => 'SHOW1_101_'.$proofNumber,
                'show_class_id' => 'SHOW1_101',
                'proof_number' => $proofNumber,
                'file_type' => 'jpg',
                'sha1' => sha1($proofNumber),
            ]);
        }
    });

    return ShowClass::find('SHOW1_101');
}

it('pushes photo metadata in chunks of 500', function () {
    $class = proofgen_seed_metadata_class(501);
    $chunkSizes = [];
    $api = Mockery::mock(FerraraphotoApiClient::class);
    $api->shouldReceive('upsertPhotos')
        ->twice()
        ->withArgs(function (ShowClass $classArg, Collection $photos) use ($class, &$chunkSizes) {
            $chunkSizes[] = $photos->count();

            return $classArg->is($class);
        })
        ->andReturn([]);

    (new PushPhotoMetadata($class->id))->handle($api);

    expect($chunkSizes)->toBe([500, 1]);
});

it('can be rerun without changing local photo metadata', function () {
    $class = proofgen_seed_metadata_class(1);
    $photo = Photo::first();
    $photo->update([
        'proof_thm_key' => 'proofs/SHOW1/101/SHOW1_00001_thm.jpg',
        'proof_std_key' => 'proofs/SHOW1/101/SHOW1_00001_std.jpg',
    ]);

    $api = Mockery::mock(FerraraphotoApiClient::class);
    $api->shouldReceive('upsertPhotos')->twice()->andReturn([]);

    (new PushPhotoMetadata($class->id))->handle($api);
    (new PushPhotoMetadata($class->id))->handle($api);

    expect($photo->fresh()->proof_thm_key)->toBe('proofs/SHOW1/101/SHOW1_00001_thm.jpg')
        ->and($photo->fresh()->proof_std_key)->toBe('proofs/SHOW1/101/SHOW1_00001_std.jpg');
});

it('does not call the API for legacy local without a token', function () {
    config(['proofgen.ferraraphoto.api_token' => null]);
    $class = proofgen_seed_metadata_class(1, StorageProfile::LEGACY_LOCAL_ID);

    $api = Mockery::mock(FerraraphotoApiClient::class);
    $api->shouldNotReceive('upsertPhotos');

    (new PushPhotoMetadata($class->id))->handle($api);
});
