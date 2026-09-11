<?php

namespace App\Jobs\Show;

use App\Models\Show;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class UploadShowHighresImages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $show_id;

    public int $tries = 5;

    /**
     * Derived in config/proofgen.php (one rsync transfer + overhead).
     */
    public ?int $timeout = null;

    public function __construct(string $show_id)
    {
        $this->show_id = $show_id;
        $this->timeout = (int) config('proofgen.uploads.single_job_timeout');
        $this->onConnection((string) config('proofgen.uploads.connection'));
        $this->onQueue((string) config('proofgen.uploads.queue'));
    }

    /**
     * Seconds to wait before each retry; the last value repeats for any extra attempt.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return array_values((array) config('proofgen.uploads.backoff'));
    }

    public function handle(): void
    {
        $show = Show::find($this->show_id);
        if (! $show) {
            Log::warning('UploadShowHighresImages: show '.$this->show_id.' not found');

            return;
        }

        $uploaded = $show->highresImageUploads();
        Log::info('UploadShowHighresImages: uploaded '.count($uploaded).' highres images for '.$this->show_id);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('UploadShowHighresImages failed for '.$this->show_id.': '.$exception?->getMessage());
    }
}
