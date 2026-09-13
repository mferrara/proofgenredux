<?php

namespace Tests\Feature;

use App\Jobs\Photo\GenerateHighresImage;
use App\Jobs\Photo\GenerateThumbnails;
use App\Jobs\Photo\GenerateWebImage;
use App\Jobs\Photo\ImportPhoto;
use App\Jobs\ShowClass\ImportClassPhotos;
use App\Services\PhotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/** Real queue admission and worker lifecycle, using SQLite jobs and synthetic PhotoService results. */
class PhotoJobUniquenessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
    }

    public function test_import_photo_same_source_with_a_different_proof_number_override_is_only_queued_once(): void
    {
        ImportPhoto::dispatch('2023R41/121/IMG_0001.jpg', '00001');
        $this->assertSame(1, $this->queuedJobCount());

        // Same source file, caller now supplies a different override. The override
        // is intentionally not part of the identity, so this must be suppressed.
        ImportPhoto::dispatch('2023R41/121/IMG_0001.jpg', '00002');
        $this->assertSame(1, $this->queuedJobCount());

        // And the resolver-owned (null override) spelling collapses onto the same
        // lock as well.
        ImportPhoto::dispatch('/2023R41//121/IMG_0001.jpg');
        $this->assertSame(1, $this->queuedJobCount());
    }

    public function test_import_photo_distinct_sources_are_allowed_to_queue_together(): void
    {
        ImportPhoto::dispatch('2023R41/121/IMG_0001.jpg');
        ImportPhoto::dispatch('2023R41/121/IMG_0002.jpg');
        ImportPhoto::dispatch('2023R41/122/IMG_0001.jpg');

        $this->assertSame(3, $this->queuedJobCount());
    }

    public function test_thumbnail_generation_for_the_same_photo_is_only_queued_once_regardless_of_destination(): void
    {
        GenerateThumbnails::dispatch('photo-1', '/proofs/2023R41/121');
        $this->assertSame(1, $this->queuedJobCount());

        // A second thumbnail job for the same photo, even pointed at another
        // destination, would race the same output type and must be suppressed.
        GenerateThumbnails::dispatch('photo-1', '/proofs/2023R41/122');
        $this->assertSame(1, $this->queuedJobCount());
    }

    public function test_output_types_are_independently_unique_but_allow_each_type_once_per_photo(): void
    {
        GenerateThumbnails::dispatch('photo-1', '/proofs/2023R41/121');
        GenerateWebImage::dispatch('photo-1', 'web_images/2023R41/121');
        GenerateHighresImage::dispatch('photo-1', 'highres_images/2023R41/121');

        // The job class is part of the framework lock key, so the three output
        // types coexist for the same photo.
        $this->assertSame(3, $this->queuedJobCount());

        // Each type is still unique against itself.
        GenerateWebImage::dispatch('photo-1', 'web_images/2023R41/999');
        GenerateHighresImage::dispatch('photo-1', 'highres_images/2023R41/999');
        $this->assertSame(3, $this->queuedJobCount());
    }

    public function test_derivative_generation_for_different_photos_is_allowed(): void
    {
        GenerateThumbnails::dispatch('photo-1', '/proofs/2023R41/121');
        GenerateThumbnails::dispatch('photo-2', '/proofs/2023R41/121');

        $this->assertSame(2, $this->queuedJobCount());
    }

    public function test_class_import_is_unique_per_show_and_class(): void
    {
        ImportClassPhotos::dispatch('2023R41', '121');
        $this->assertSame(1, $this->queuedJobCount());

        ImportClassPhotos::dispatch('2023R41', '121');
        $this->assertSame(1, $this->queuedJobCount());
    }

    public function test_class_import_allows_distinct_classes_and_distinct_shows(): void
    {
        ImportClassPhotos::dispatch('2023R41', '121');
        ImportClassPhotos::dispatch('2023R41', '122');
        ImportClassPhotos::dispatch('2024R01', '121');

        $this->assertSame(3, $this->queuedJobCount());
    }

    public function test_unique_lock_is_held_while_a_job_waits_and_runs_then_released_on_success(): void
    {
        $jobCountSeenInsideHandle = null;

        $this->mock(PhotoService::class, function (MockInterface $mock) use (&$jobCountSeenInsideHandle): void {
            $mock->shouldReceive('generateThumbnails')->once()->andReturnUsing(function () use (&$jobCountSeenInsideHandle) {
                // The worker has reserved this job but not finished it. A second
                // dispatch of the same unique job must be rejected while handle()
                // runs, so only the in-flight row is visible.
                GenerateThumbnails::dispatch('photo-1', '/proofs/elsewhere');
                $jobCountSeenInsideHandle = $this->queuedJobCount();

                return '/proofs/photo-1.jpg';
            });
        });

        GenerateThumbnails::dispatch('photo-1', '/proofs/here');
        $this->assertSame(1, $this->queuedJobCount());

        // Waiting in the queue: the lock is already held, so a different
        // destination for the same photo cannot be admitted.
        GenerateThumbnails::dispatch('photo-1', '/proofs/other-destination');
        $this->assertSame(1, $this->queuedJobCount());

        $this->runOneJob();

        $this->assertSame(
            1,
            $jobCountSeenInsideHandle,
            'A duplicate dispatch was admitted while the unique job was running.'
        );
        $this->assertSame(0, $this->queuedJobCount());

        // Completed successfully: the framework released the lock, so the photo can
        // be queued again.
        GenerateThumbnails::dispatch('photo-1', '/proofs/here');
        $this->assertSame(1, $this->queuedJobCount());
    }

    public function test_unique_lock_remains_through_a_retryable_failure_and_is_released_after_eventual_success(): void
    {
        $attempts = 0;

        $this->mock(PhotoService::class, function (MockInterface $mock) use (&$attempts): void {
            $mock->shouldReceive('processPhoto')->andReturnUsing(function () use (&$attempts) {
                $attempts++;

                if ($attempts === 1) {
                    throw new RuntimeException('transient decode failure');
                }

                return ['photo' => null];
            });
        });

        ImportPhoto::dispatch('2023R41/121/IMG_0007.jpg');
        $this->assertSame(1, $this->queuedJobCount());

        $this->runOneJob(maxTries: 3);

        // The attempt failed and the job was released for a retry. The unique lock
        // must still be held while it sits back on the queue.
        $this->assertSame(1, $this->queuedJobCount());
        ImportPhoto::dispatch('2023R41/121/IMG_0007.jpg');
        $this->assertSame(1, $this->queuedJobCount());

        $this->runOneJob(maxTries: 3);

        $this->assertSame(2, $attempts);
        $this->assertSame(0, $this->queuedJobCount());

        // Eventual success released the lock.
        ImportPhoto::dispatch('2023R41/121/IMG_0007.jpg');
        $this->assertSame(1, $this->queuedJobCount());
    }

    public function test_unique_lock_is_released_after_the_job_fails_permanently(): void
    {
        Event::fake([JobFailed::class]);
        $this->mock(PhotoService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('processPhoto')->andThrow(new RuntimeException('permanent decode failure'));
        });

        ImportPhoto::dispatch('2023R41/121/IMG_0008.jpg');
        $this->assertSame(1, $this->queuedJobCount());

        $this->runOneJob(maxTries: 1);

        $this->assertSame(0, $this->queuedJobCount());
        Event::assertDispatched(JobFailed::class, fn ($event) => $event->exception->getMessage() === 'permanent decode failure');

        // Permanent failure released the lock, so the operator can re-queue.
        ImportPhoto::dispatch('2023R41/121/IMG_0008.jpg');
        $this->assertSame(1, $this->queuedJobCount());
    }

    private function runOneJob(int $maxTries = 1): void
    {
        $worker = $this->app->make('queue.worker');

        $worker->runNextJob('database', 'default', new WorkerOptions(
            name: 'default',
            backoff: 0,
            memory: 128,
            timeout: 30,
            sleep: 0,
            maxTries: $maxTries,
        ));
    }

    private function queuedJobCount(): int
    {
        return DB::table('jobs')->where('queue', 'default')->count();
    }
}
