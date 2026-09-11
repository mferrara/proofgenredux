<?php

namespace Tests\Feature\Queue;

use App\Jobs\Show\UploadShowHighresImages;
use App\Jobs\Show\UploadShowProofs;
use App\Jobs\Show\UploadShowWebImages;
use App\Jobs\ShowClass\PushPhotoMetadata;
use App\Jobs\ShowClass\UploadDerivedFiles;
use App\Jobs\ShowClass\UploadHighresImages;
use App\Jobs\ShowClass\UploadProofs;
use App\Jobs\ShowClass\UploadWebImages;
use App\Models\Photo;
use App\Models\StorageProfile;
use App\Services\Ferraraphoto\FerraraphotoApiClient;
use App\Services\Transport\RsyncFailedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Feature\Concerns\InteractsWithLocalRsyncUploads;
use Tests\TestCase;
use Throwable;

/**
 * Upload job routing contract:
 *
 *  - every long upload job declares the dedicated `uploads` connection/queue,
 *    the derived timeout, bounded tries and a backoff,
 *  - real dispatches (database driver standing in for Redis) land on the
 *    `uploads` queue with the worker-visible payload values,
 *  - the shipped chain runs under the sync connection in tests without
 *    reaching Redis (local rsync), and a transport failure still stops the
 *    chain before metadata is pushed.
 */
class UploadQueueRoutingTest extends TestCase
{
    use InteractsWithLocalRsyncUploads;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLocalRsyncUploads();
    }

    protected function tearDown(): void
    {
        $this->tearDownLocalRsyncUploads();

        parent::tearDown();
    }

    /**
     * @return array<int, object>
     */
    private function singleTransferJobs(): array
    {
        return [
            new UploadShowProofs('SHOW1'),
            new UploadShowWebImages('SHOW1'),
            new UploadShowHighresImages('SHOW1'),
            new UploadProofs('SHOW1', '101'),
            new UploadWebImages('SHOW1', '101'),
            new UploadHighresImages('SHOW1', '101'),
        ];
    }

    public function test_upload_jobs_declare_the_uploads_connection_queue_timeout_and_backoff(): void
    {
        $connection = (string) config('proofgen.uploads.connection');
        $queue = (string) config('proofgen.uploads.queue');
        $single = (int) config('proofgen.uploads.single_job_timeout');
        $derived = (int) config('proofgen.uploads.derived_job_timeout');
        $backoff = config('proofgen.uploads.backoff');

        foreach ($this->singleTransferJobs() as $job) {
            $this->assertSame($connection, $job->connection, $job::class.' connection');
            $this->assertSame($queue, $job->queue, $job::class.' queue');
            $this->assertSame($single, $job->timeout, $job::class.' timeout');
            $this->assertSame(5, $job->tries, $job::class.' tries');
            $this->assertSame($backoff, $job->backoff(), $job::class.' backoff');
        }

        $derivedJob = new UploadDerivedFiles('SHOW1_101');
        $this->assertSame($derived, $derivedJob->timeout, 'UploadDerivedFiles must cover three transfers.');
        $this->assertSame($connection, $derivedJob->connection);
        $this->assertSame($queue, $derivedJob->queue);
        $this->assertSame(5, $derivedJob->tries);
        $this->assertSame($backoff, $derivedJob->backoff());
    }

    public function test_real_dispatch_lands_on_the_uploads_queue_with_the_worker_payload_budget(): void
    {
        // The database driver stands in for Redis so the real dispatcher (not a
        // fake) builds the payload the worker reads.
        config(['queue.connections.uploads' => [
            'driver' => 'database',
            'connection' => null,
            'table' => 'jobs',
            'queue' => (string) config('proofgen.uploads.queue'),
            'retry_after' => 90,
            'after_commit' => false,
        ]]);

        UploadProofs::dispatch('SHOW1', '101');

        $row = DB::table('jobs')->where('queue', (string) config('proofgen.uploads.queue'))->first();
        $this->assertNotNull($row, 'UploadProofs was not pushed onto the uploads queue.');

        $payload = json_decode($row->payload, true);
        $this->assertSame((int) config('proofgen.uploads.single_job_timeout'), $payload['timeout']);
        $this->assertSame(implode(',', config('proofgen.uploads.backoff')), $payload['backoff']);
        $this->assertSame(5, $payload['maxTries']);
    }

    public function test_every_upload_dispatch_path_uses_the_uploads_queue(): void
    {
        Queue::fake();

        UploadShowProofs::dispatch('SHOW1');
        UploadShowWebImages::dispatch('SHOW1');
        UploadShowHighresImages::dispatch('SHOW1');
        UploadProofs::dispatch('SHOW1', '101');
        UploadWebImages::dispatch('SHOW1', '101');
        UploadHighresImages::dispatch('SHOW1', '101');
        UploadDerivedFiles::dispatch('SHOW1_101');

        $classes = [
            UploadShowProofs::class,
            UploadShowWebImages::class,
            UploadShowHighresImages::class,
            UploadProofs::class,
            UploadWebImages::class,
            UploadHighresImages::class,
            UploadDerivedFiles::class,
        ];

        foreach ($classes as $class) {
            Queue::assertPushedOn((string) config('proofgen.uploads.queue'), $class);
            Queue::assertPushed($class, function ($job) {
                return $job->connection === (string) config('proofgen.uploads.connection');
            });
        }
    }

    public function test_sync_chain_runs_derived_upload_then_metadata_without_touching_redis(): void
    {
        $proofNumber = 'SHOW1_00030';
        $this->seedPhotoWithAllDerivatives($proofNumber);
        $this->pinLegacyProfile();

        $api = Mockery::mock(FerraraphotoApiClient::class);
        $api->shouldReceive('upsertPhotos')->once();
        $this->app->instance(FerraraphotoApiClient::class, $api);

        // If the uploads connection were still Redis, this dispatch would try to
        // connect; the test harness pins the driver to sync.
        $this->assertInstanceOf(SyncQueue::class, app('queue')->connection('uploads'));

        Bus::chain([
            new UploadDerivedFiles('SHOW1_101'),
            new PushPhotoMetadata('SHOW1_101'),
        ])->dispatch();

        $this->assertTrue(Storage::disk('remote_proofs')->exists('SHOW1/101/'.$proofNumber.$this->smallSuffix().'.jpg'));
        $this->assertTrue(Storage::disk('remote_proofs')->exists('SHOW1/101/'.$proofNumber.$this->largeSuffix().'.jpg'));
        $this->assertTrue(Storage::disk('remote_web_images')->exists('SHOW1/101/'.$proofNumber.$this->webSuffix().'.jpg'));
        $this->assertTrue(Storage::disk('remote_highres_images')->exists('SHOW1/101/'.$proofNumber.$this->highresSuffix().'.jpg'));

        $photo = Photo::find('SHOW1_101_'.$proofNumber);
        $this->assertNotNull($photo->proofs_uploaded_at);
        $this->assertNotNull($photo->web_image_uploaded_at);
        $this->assertNotNull($photo->highres_image_uploaded_at);
    }

    public function test_sync_chain_stops_before_metadata_when_the_upload_transport_fails(): void
    {
        $proofNumber = 'SHOW1_00031';
        $this->seedPhotoWithAllDerivatives($proofNumber);
        $this->pinLegacyProfile();

        $this->bindFakeRsync("#!/bin/sh\necho 'simulated rsync failure' >&2\nexit 12\n");

        $api = Mockery::mock(FerraraphotoApiClient::class);
        $api->shouldNotReceive('upsertPhotos');
        $this->app->instance(FerraraphotoApiClient::class, $api);

        try {
            Bus::chain([
                new UploadDerivedFiles('SHOW1_101'),
                new PushPhotoMetadata('SHOW1_101'),
            ])->dispatch();
            $this->fail('Expected the transport failure to abort the chain.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(RsyncFailedException::class, $e);
        }

        $photo = Photo::find('SHOW1_101_'.$proofNumber);
        $this->assertNull($photo->proofs_uploaded_at);
        $this->assertNull($photo->web_image_uploaded_at);
        $this->assertNull($photo->highres_image_uploaded_at);
    }

    private function pinLegacyProfile(): void
    {
        $profile = StorageProfile::findOrFail(StorageProfile::LEGACY_LOCAL_ID);
        $this->show->storage_profile_id = $profile->id;
        $this->show->save();

        config(['proofgen.ferraraphoto.api_token' => 'unit-test-token']);
    }

    private function seedPhotoWithAllDerivatives(string $proofNumber): Photo
    {
        $photo = Photo::create([
            'id' => 'SHOW1_101_'.$proofNumber,
            'show_class_id' => 'SHOW1_101',
            'proof_number' => $proofNumber,
            'file_type' => 'jpg',
            'sha1' => sha1($proofNumber.'_all'),
            'proofs_generated_at' => Carbon::now()->subMinute(),
            'web_image_generated_at' => Carbon::now()->subMinute(),
            'highres_image_generated_at' => Carbon::now()->subMinute(),
        ]);

        Storage::disk('fullsize')->put('proofs/SHOW1/101/'.$proofNumber.$this->smallSuffix().'.jpg', 'thm '.$proofNumber);
        Storage::disk('fullsize')->put('proofs/SHOW1/101/'.$proofNumber.$this->largeSuffix().'.jpg', 'std '.$proofNumber);
        Storage::disk('fullsize')->put('web_images/SHOW1/101/'.$proofNumber.$this->webSuffix().'.jpg', 'web '.$proofNumber);
        Storage::disk('fullsize')->put('highres_images/SHOW1/101/'.$proofNumber.$this->highresSuffix().'.jpg', 'highres '.$proofNumber);

        return $photo->fresh();
    }

    private function smallSuffix(): string
    {
        return (string) config('proofgen.thumbnails.small.suffix');
    }

    private function largeSuffix(): string
    {
        return (string) config('proofgen.thumbnails.large.suffix');
    }

    private function webSuffix(): string
    {
        return (string) config('proofgen.web_images.suffix');
    }

    private function highresSuffix(): string
    {
        return (string) config('proofgen.highres_images.suffix');
    }
}
