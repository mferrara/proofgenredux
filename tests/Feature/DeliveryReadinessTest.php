<?php

namespace Tests\Feature;

use App\Jobs\ShowClass\DeliverClassOutputs;
use App\Models\Configuration;
use App\Models\StorageProfile;
use App\Services\AutomaticUploadSettings;
use App\Services\Transport\RsyncFailedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\InteractsWithLocalRsyncUploads;
use Tests\TestCase;

class DeliveryReadinessTest extends TestCase
{
    use InteractsWithLocalRsyncUploads;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLocalRsyncUploads();
        config(['proofgen.upload_proofs' => true, 'proofgen.ferraraphoto.api_token' => 'test-token']);
        Configuration::setConfig('upload_proofs', 'true', 'boolean');
        Http::fake(['*' => Http::response(['data' => []])]);
    }

    protected function tearDown(): void
    {
        $this->tearDownLocalRsyncUploads();
        parent::tearDown();
    }

    public function test_saved_switch_overrides_stale_worker_configuration_in_both_directions(): void
    {
        $settings = app(AutomaticUploadSettings::class);
        Configuration::setConfig('upload_proofs', 'false', 'boolean');
        $this->assertFalse($settings->enabled());

        config(['proofgen.upload_proofs' => false]);
        Configuration::setConfig('upload_proofs', 'true', 'boolean');
        $this->assertTrue($settings->enabled());
    }

    public function test_queued_automatic_delivery_obeys_off_switch_but_manual_delivery_still_runs(): void
    {
        $photo = $this->seedPhotoWithProofs('SHOW1_00001');
        $this->show->update(['storage_profile_id' => StorageProfile::LEGACY_LOCAL_ID]);
        $automatic = new DeliverClassOutputs($this->class->id, automatic: true);
        Configuration::setConfig('upload_proofs', 'false', 'boolean');

        $automatic->handle();
        Http::assertNothingSent();
        $this->assertNull($photo->fresh()->proofs_uploaded_at);

        (new DeliverClassOutputs($this->class->id))->handle();
        $this->assertNotNull($photo->fresh()->proofs_uploaded_at);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/photos'));
    }

    public function test_legacy_auto_delivery_leaves_unfinished_kinds_until_their_later_completion(): void
    {
        $this->assertReadiness(false);
    }

    public function test_cloud_auto_delivery_leaves_unfinished_kinds_until_their_later_completion(): void
    {
        $this->assertReadiness(true);
    }

    private function assertReadiness(bool $cloud): void
    {
        $profile = $cloud
            ? StorageProfile::factory()->local()->create(['id' => 'delivery-cloud', 'root' => $this->tempPath.'/cloud'])
            : StorageProfile::findOrFail(StorageProfile::LEGACY_LOCAL_ID);
        $this->show->update(['storage_profile_id' => $profile->id, 'ferraraphoto_show_slug' => 'remote-show']);
        $first = $this->seedPhotoWithProofs('SHOW1_00001');
        $second = $this->seedPhotoWithProofs('SHOW1_00002');
        $first->update(['web_image_generated_at' => now()]);
        $source = Storage::disk('fullsize');
        $webSuffix = config('proofgen.web_images.suffix');
        $source->put('web_images/SHOW1/101/SHOW1_00001'.$webSuffix.'.jpg', 'finished first web');
        $source->put('web_images/SHOW1/101/SHOW1_00002'.$webSuffix.'.jpg', 'incomplete second web');

        (new DeliverClassOutputs($this->class->id, automatic: true))->handle();
        $this->assertNotNull($first->fresh()->proofs_uploaded_at);
        $this->assertNotNull($second->fresh()->proofs_uploaded_at);
        $this->assertNull($first->fresh()->web_image_uploaded_at);
        $this->assertNull($second->fresh()->web_image_uploaded_at);
        $target = Storage::disk($cloud ? 'profile_delivery-cloud' : 'remote_web_images');
        $prefix = $cloud ? 'web_images/' : '';
        $key = $prefix.'remote-show/101/SHOW1_00002'.$webSuffix.'.jpg';
        $this->assertFalse($target->exists($key));

        $source->put('web_images/SHOW1/101/SHOW1_00002'.$webSuffix.'.jpg', 'finished second web');
        $second->update(['web_image_generated_at' => now()]);
        (new DeliverClassOutputs($this->class->id, automatic: true))->handle();
        $this->assertSame('finished second web', $target->get($key));
        $this->assertNotNull($first->fresh()->web_image_uploaded_at);
        $this->assertNotNull($second->fresh()->web_image_uploaded_at);
    }

    public function test_cloud_retry_preserves_uploaded_bytes_and_stamps_but_still_pushes_metadata(): void
    {
        $profile = StorageProfile::factory()->local()->create(['id' => 'delivery-cloud', 'root' => $this->tempPath.'/cloud']);
        $this->show->update(['storage_profile_id' => $profile->id]);
        $photo = $this->seedPhotoWithProofs('SHOW1_00001');
        $job = new DeliverClassOutputs($this->class->id);
        $job->handle();
        $photo->refresh();
        $stamp = $photo->proofs_uploaded_at;
        $target = Storage::disk('profile_delivery-cloud');
        $target->put($photo->proof_std_key, 'already delivered marker');

        $this->travel(10)->seconds();
        $job->handle();
        $this->assertSame('already delivered marker', $target->get($photo->proof_std_key));
        $this->assertTrue($stamp->equalTo($photo->fresh()->proofs_uploaded_at));
        $this->assertCount(2, Http::recorded(fn ($request) => str_ends_with($request->url(), '/photos')));
    }

    public function test_transfer_failure_in_shared_job_stops_before_photo_metadata(): void
    {
        $photo = $this->seedPhotoWithProofs('SHOW1_00001');
        $this->show->update(['storage_profile_id' => StorageProfile::LEGACY_LOCAL_ID]);
        $this->bindFakeRsync("#!/bin/sh\nexit 12\n");

        try {
            (new DeliverClassOutputs($this->class->id))->handle();
            $this->fail('Expected the transfer to fail.');
        } catch (RsyncFailedException) {
            $this->assertNull($photo->fresh()->proofs_uploaded_at);
            Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/photos'));
        }
    }

    public function test_explicit_proof_only_delivery_does_not_upload_paid_images(): void
    {
        $photo = $this->seedPhotoWithProofs('SHOW1_00001');
        $photo->update(['web_image_generated_at' => now()]);
        $this->show->update(['storage_profile_id' => StorageProfile::LEGACY_LOCAL_ID]);
        Storage::disk('fullsize')->put('web_images/SHOW1/101/SHOW1_00001'.config('proofgen.web_images.suffix').'.jpg', 'web');

        (new DeliverClassOutputs($this->class->id, kinds: ['proofs']))->handle();
        $this->assertNotNull($photo->fresh()->proofs_uploaded_at);
        $this->assertNull($photo->fresh()->web_image_uploaded_at);
        $this->assertSame([], Storage::disk('remote_web_images')->allFiles());
    }
}
