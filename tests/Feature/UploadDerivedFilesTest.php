<?php

use App\Jobs\ShowClass\UploadDerivedFiles;
use App\Jobs\ShowClass\UploadHighresImages;
use App\Jobs\ShowClass\UploadProofs;
use App\Jobs\ShowClass\UploadWebImages;
use App\Models\Photo;
use App\Models\Show;
use App\Models\ShowClass;
use App\Models\StorageProfile;
use App\Services\PathResolver;
use App\Services\Storage\StorageProfileResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('fullsize');
    $this->cloudRoot = storage_path('app/testing-cloud-profile');
    File::ensureDirectoryExists($this->cloudRoot);
});

afterEach(function () {
    if (isset($this->cloudRoot) && File::exists($this->cloudRoot)) {
        File::deleteDirectory($this->cloudRoot);
    }
});

it('copies derived files to a cloud profile and records object keys', function () {
    $profile = StorageProfile::factory()->local()->create([
        'id' => 'cloud',
        'root' => $this->cloudRoot,
    ]);

    Show::withoutEvents(fn () => Show::create([
        'id' => 'SHOW1',
        'name' => 'Show One',
        'ferraraphoto_show_slug' => 'remote-show',
        'storage_profile_id' => $profile->id,
    ]));
    ShowClass::withoutEvents(fn () => ShowClass::create(['id' => 'SHOW1_101', 'show_id' => 'SHOW1', 'name' => '101']));
    Photo::withoutEvents(fn () => Photo::create([
        'id' => 'SHOW1_101_SHOW1_00001',
        'show_class_id' => 'SHOW1_101',
        'proof_number' => 'SHOW1_00001',
        'file_type' => 'jpg',
        'sha1' => sha1('photo'),
        'proofs_generated_at' => Carbon::now()->subMinute(),
        'web_image_generated_at' => Carbon::now()->subMinute(),
        'highres_image_generated_at' => Carbon::now()->subMinute(),
    ]));

    Storage::disk('fullsize')->put('proofs/SHOW1/101/SHOW1_00001'.config('proofgen.thumbnails.small.suffix').'.jpg', 'thm');
    Storage::disk('fullsize')->put('proofs/SHOW1/101/SHOW1_00001'.config('proofgen.thumbnails.large.suffix').'.jpg', 'std');
    Storage::disk('fullsize')->put('web_images/SHOW1/101/SHOW1_00001'.config('proofgen.web_images.suffix').'.jpg', 'web');
    Storage::disk('fullsize')->put('highres_images/SHOW1/101/SHOW1_00001'.config('proofgen.highres_images.suffix').'.jpg', 'highres');

    (new UploadDerivedFiles('SHOW1_101'))->handle(app(StorageProfileResolver::class), app(PathResolver::class));

    $disk = Storage::disk('profile_cloud');
    expect($disk->get('proofs/remote-show/101/SHOW1_00001'.config('proofgen.thumbnails.small.suffix').'.jpg'))->toBe('thm')
        ->and($disk->get('proofs/remote-show/101/SHOW1_00001'.config('proofgen.thumbnails.large.suffix').'.jpg'))->toBe('std')
        ->and($disk->get('web_images/remote-show/101/SHOW1_00001'.config('proofgen.web_images.suffix').'.jpg'))->toBe('web')
        ->and($disk->get('highres_images/remote-show/101/SHOW1_00001'.config('proofgen.highres_images.suffix').'.jpg'))->toBe('highres');

    $photo = Photo::find('SHOW1_101_SHOW1_00001');

    expect($photo->proof_thm_key)->toBe('proofs/remote-show/101/SHOW1_00001'.config('proofgen.thumbnails.small.suffix').'.jpg')
        ->and($photo->proof_std_key)->toBe('proofs/remote-show/101/SHOW1_00001'.config('proofgen.thumbnails.large.suffix').'.jpg')
        ->and($photo->web_image_key)->toBe('web_images/remote-show/101/SHOW1_00001'.config('proofgen.web_images.suffix').'.jpg')
        ->and($photo->high_res_image_key)->toBe('highres_images/remote-show/101/SHOW1_00001'.config('proofgen.highres_images.suffix').'.jpg')
        ->and($photo->proofs_uploaded_at)->not->toBeNull()
        ->and($photo->web_image_uploaded_at)->not->toBeNull()
        ->and($photo->highres_image_uploaded_at)->not->toBeNull();
});

it('delegates legacy local uploads to the existing rsync jobs', function () {
    Bus::fake();

    Show::withoutEvents(fn () => Show::create([
        'id' => 'SHOW1',
        'name' => 'Show One',
        'storage_profile_id' => StorageProfile::LEGACY_LOCAL_ID,
    ]));
    ShowClass::withoutEvents(fn () => ShowClass::create(['id' => 'SHOW1_101', 'show_id' => 'SHOW1', 'name' => '101']));
    Photo::withoutEvents(fn () => Photo::create([
        'id' => 'SHOW1_101_SHOW1_00002',
        'show_class_id' => 'SHOW1_101',
        'proof_number' => 'SHOW1_00002',
        'file_type' => 'jpg',
        'sha1' => sha1('legacy-photo'),
        'proofs_generated_at' => Carbon::now()->subMinute(),
        'web_image_generated_at' => Carbon::now()->subMinute(),
        'highres_image_generated_at' => Carbon::now()->subMinute(),
    ]));

    (new UploadDerivedFiles('SHOW1_101'))->handle(app(StorageProfileResolver::class), app(PathResolver::class));

    Bus::assertDispatchedSync(UploadProofs::class);
    Bus::assertDispatchedSync(UploadWebImages::class);
    Bus::assertDispatchedSync(UploadHighresImages::class);
});
