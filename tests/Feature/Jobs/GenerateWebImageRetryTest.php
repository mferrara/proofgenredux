<?php

namespace Tests\Feature\Jobs;

use App\Jobs\Photo\GenerateWebImage;
use App\Services\PhotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * GenerateWebImage retry/backoff contract: a bounded number of attempts with a
 * real backoff the worker can read from the queued payload.
 */
class GenerateWebImageRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_retries_and_backoff_reach_the_worker_payload(): void
    {
        $job = new GenerateWebImage('photo-id', 'web_images/SHOW1/121');

        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300], $job->backoff());

        config(['queue.default' => 'database']);

        GenerateWebImage::dispatch('photo-id', 'web_images/SHOW1/121');

        $row = DB::table('jobs')->first();
        $this->assertNotNull($row, 'GenerateWebImage was not pushed onto the database queue.');

        $payload = json_decode($row->payload, true);
        $this->assertSame(3, $payload['maxTries']);
        $this->assertSame('60,300', $payload['backoff']);
        // No job-level timeout: the thumbnails connection's retry_after (90s)
        // must stay above any job timeout, so the 60s supervisor worker timeout
        // remains the effective budget.
        $this->assertNull($payload['timeout']);
    }

    public function test_handle_rethrows_so_the_queue_can_retry(): void
    {
        $service = $this->mock(PhotoService::class);
        $service->shouldReceive('generateWebImage')->once()->andThrow(new RuntimeException('decode failure'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('decode failure');

        (new GenerateWebImage('photo-id', 'web_images/SHOW1/121'))->handle();
    }
}
