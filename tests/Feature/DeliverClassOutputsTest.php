<?php

namespace Tests\Feature;

use App\Jobs\ShowClass\DeliverClassOutputs;
use App\Models\Photo;
use App\Models\Show;
use App\Models\ShowClass;
use App\Models\StorageProfile;
use App\Services\QueuedWorkStatus;
use App\Services\Transport\RsyncFailedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Feature\Concerns\InteractsWithLocalRsyncUploads;
use Tests\TestCase;

/**
 * DeliverClassOutputs behavior: the unified delivery run used by both the
 * automatic generation trigger and the explicit operator upload actions.
 *
 * Legacy-local runs real local rsync into throwaway directories; cloud
 * profiles copy into a throwaway local profile root; the API is always faked.
 */
class DeliverClassOutputsTest extends TestCase
{
    use InteractsWithLocalRsyncUploads;
    use RefreshDatabase;

    protected string $cloudRoot;

    protected function tearDown(): void
    {
        if (isset($this->cloudRoot) && File::exists($this->cloudRoot)) {
            File::deleteDirectory($this->cloudRoot);
        }

        parent::tearDown();
    }

    /**
     * One photo with all three derivative kinds generated (stamped) and the
     * matching files on the fullsize disk, none of them uploaded yet.
     */
    protected function seedFullyGeneratedPhoto(): Photo
    {
        $stamp = now()->subMinute();

        Photo::withoutEvents(fn () => Photo::create([
            'id' => 'SHOW1_101_SHOW1_00001',
            'show_class_id' => 'SHOW1_101',
            'proof_number' => 'SHOW1_00001',
            'file_type' => 'jpg',
            'sha1' => sha1('SHOW1_00001'),
            'proofs_generated_at' => $stamp,
            'web_image_generated_at' => $stamp,
            'highres_image_generated_at' => $stamp,
        ]));

        $smallSuffix = (string) config('proofgen.thumbnails.small.suffix');
        $largeSuffix = (string) config('proofgen.thumbnails.large.suffix');
        $webSuffix = (string) config('proofgen.web_images.suffix');
        $highresSuffix = (string) config('proofgen.highres_images.suffix');

        Storage::disk('fullsize')->put('proofs/SHOW1/101/SHOW1_00001'.$smallSuffix.'.jpg', 'thm bytes');
        Storage::disk('fullsize')->put('proofs/SHOW1/101/SHOW1_00001'.$largeSuffix.'.jpg', 'std bytes');
        Storage::disk('fullsize')->put('web_images/SHOW1/101/SHOW1_00001'.$webSuffix.'.jpg', 'web bytes');
        Storage::disk('fullsize')->put('highres_images/SHOW1/101/SHOW1_00001'.$highresSuffix.'.jpg', 'highres bytes');

        return Photo::find('SHOW1_101_SHOW1_00001');
    }

    protected function seedLegacyLocalProfile(): void
    {
        // The legacy-local profile row itself is seeded by a migration; pin the show.
        Show::withoutEvents(fn () => $this->show->update(['storage_profile_id' => StorageProfile::LEGACY_LOCAL_ID]));
    }

    public function test_delivers_legacy_local_class_over_real_local_rsync_without_touching_the_api(): void
    {
        $this->setUpLocalRsyncUploads();
        config(['proofgen.ferraraphoto.api_token' => null]);
        $this->seedLegacyLocalProfile();
        $photo = $this->seedFullyGeneratedPhoto();

        // Http::preventStrayRequests() is active from the base TestCase: any
        // API call would throw, so a green run proves the API was skipped.
        (new DeliverClassOutputs('SHOW1_101'))->handle();

        $photo = $photo->fresh();
        expect($photo->proofs_uploaded_at)->not->toBeNull()
            ->and($photo->web_image_uploaded_at)->not->toBeNull()
            ->and($photo->highres_image_uploaded_at)->not->toBeNull();

        $smallSuffix = (string) config('proofgen.thumbnails.small.suffix');
        $largeSuffix = (string) config('proofgen.thumbnails.large.suffix');
        $webSuffix = (string) config('proofgen.web_images.suffix');
        $highresSuffix = (string) config('proofgen.highres_images.suffix');

        expect(Storage::disk('remote_proofs')->get('SHOW1/101/SHOW1_00001'.$smallSuffix.'.jpg'))->toBe('thm bytes')
            ->and(Storage::disk('remote_proofs')->get('SHOW1/101/SHOW1_00001'.$largeSuffix.'.jpg'))->toBe('std bytes')
            ->and(Storage::disk('remote_web_images')->get('SHOW1/101/SHOW1_00001'.$webSuffix.'.jpg'))->toBe('web bytes')
            ->and(Storage::disk('remote_highres_images')->get('SHOW1/101/SHOW1_00001'.$highresSuffix.'.jpg'))->toBe('highres bytes');

        $this->tearDownLocalRsyncUploads();
    }

    public function test_delivers_to_a_pinned_cloud_profile_using_the_ferraraphoto_slug_and_pushes_metadata(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        config([
            'testing.skip_file_operations' => true,
            'proofgen.ferraraphoto.api_token' => 'test-token',
            'proofgen.thumbnails.small.suffix' => '_thm',
            'proofgen.thumbnails.large.suffix' => '_std',
            'proofgen.web_images.suffix' => '_web',
            'proofgen.highres_images.suffix' => '_highres',
        ]);

        Storage::fake('fullsize');

        $this->cloudRoot = storage_path('app/delivery-cloud-'.uniqid());
        $profile = StorageProfile::factory()->local()->create(['id' => 'cloud', 'root' => $this->cloudRoot]);

        Show::withoutEvents(fn () => Show::create([
            'id' => 'SHOW1',
            'name' => 'Show One',
            'ferraraphoto_show_slug' => 'remote-show',
            'storage_profile_id' => $profile->id,
        ]));
        ShowClass::withoutEvents(fn () => ShowClass::create(['id' => 'SHOW1_101', 'show_id' => 'SHOW1', 'name' => '101']));

        $photo = $this->seedFullyGeneratedPhoto();

        (new DeliverClassOutputs('SHOW1_101'))->handle();

        $photo = $photo->fresh();
        expect($photo->proof_thm_key)->toBe('proofs/remote-show/101/SHOW1_00001_thm.jpg')
            ->and($photo->proof_std_key)->toBe('proofs/remote-show/101/SHOW1_00001_std.jpg')
            ->and($photo->web_image_key)->toBe('web_images/remote-show/101/SHOW1_00001_web.jpg')
            ->and($photo->high_res_image_key)->toBe('highres_images/remote-show/101/SHOW1_00001_highres.jpg')
            ->and($photo->proofs_uploaded_at)->not->toBeNull()
            ->and($photo->web_image_uploaded_at)->not->toBeNull()
            ->and($photo->highres_image_uploaded_at)->not->toBeNull();

        $disk = Storage::disk('profile_cloud');
        expect($disk->get('proofs/remote-show/101/SHOW1_00001_thm.jpg'))->toBe('thm bytes')
            ->and($disk->get('proofs/remote-show/101/SHOW1_00001_std.jpg'))->toBe('std bytes')
            ->and($disk->get('web_images/remote-show/101/SHOW1_00001_web.jpg'))->toBe('web bytes')
            ->and($disk->get('highres_images/remote-show/101/SHOW1_00001_highres.jpg'))->toBe('highres bytes');

        // Ordered API traffic: profile + show + class upserts, then photo metadata.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/storage-profiles'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/v1/shows'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/shows/remote-show/classes'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/shows/remote-show/classes/101/photos'));
    }

    public function test_does_not_push_metadata_when_the_file_transfer_fails(): void
    {
        $this->setUpLocalRsyncUploads();
        config(['proofgen.ferraraphoto.api_token' => 'test-token']);
        $this->seedLegacyLocalProfile();
        $this->seedFullyGeneratedPhoto();

        Http::fake(['*' => Http::response([], 200)]);

        // Transfer always fails.
        $this->bindFakeRsync("#!/bin/sh\necho 'rsync: connection refused' >&2\nexit 5\n");

        expect(fn () => (new DeliverClassOutputs('SHOW1_101'))->handle())
            ->toThrow(RsyncFailedException::class);

        // The failed transfer stops the chain: show/class sync happened, the
        // photo metadata push did not.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/photos'));

        $this->tearDownLocalRsyncUploads();
    }

    public function test_marks_queued_delivery_jobs_busy_for_their_class(): void
    {
        config(['testing.skip_file_operations' => true]);
        Show::withoutEvents(fn () => Show::create(['id' => 'SHOW1', 'name' => 'SHOW1']));
        ShowClass::withoutEvents(fn () => ShowClass::create(['id' => 'SHOW1_101', 'show_id' => 'SHOW1', 'name' => '101']));

        $job = new DeliverClassOutputs('SHOW1_101');
        $payload = json_encode(['uuid' => uniqid(), 'data' => ['command' => serialize($job)]]);

        $service = Mockery::mock(QueuedWorkStatus::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('queuedPayloads')->andReturn([['waiting', $payload]]);
        app()->instance(QueuedWorkStatus::class, $service);

        expect($service->snapshot('SHOW1', '101'))->toMatchArray(['available' => true, 'busy' => true, 'waiting' => 1]);
        expect($service->snapshot('SHOW1', '102')['busy'])->toBeFalse();
    }
}
