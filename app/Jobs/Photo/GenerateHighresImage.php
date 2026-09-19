<?php

namespace App\Jobs\Photo;

use App\Services\PhotoService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateHighresImage implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $photo_id;

    public string $highres_destination_path;

    /**
     * A highres render failure is usually transient at a show (the Core Image
     * daemon busy, a mounted drive that blinks), so retry a couple of times
     * instead of dropping the photo on the floor after one bad attempt.
     */
    public int $tries = 3;

    /**
     * Seconds to wait before each retry; the final value repeats for any extra
     * attempt. This job runs on the generate-highres queue, whose redis
     * connection retry_after (90s) is the ceiling for any job timeout, so no
     * job-level timeout is set here.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    /**
     * Create a new job instance.
     */
    public function __construct(string $photo_id, string $highres_destination_path)
    {
        $this->photo_id = $photo_id;
        $this->highres_destination_path = $highres_destination_path;
    }

    /**
     * Execute the job.
     *
     * @throws Exception
     */
    public function handle(): void
    {
        ini_set('memory_limit', '-1');
        $photoService = app(PhotoService::class);
        try {
            $photoService->generateHighresImage($this->photo_id, $this->highres_destination_path);
        } catch (Exception $e) {
            Log::error('Error generating highres image: '.$e->getMessage());
            throw $e;
        }
    }

    /** One job per photo/output type, held through processing and retries. */
    public function uniqueId(): string
    {
        return $this->photo_id;
    }
}
