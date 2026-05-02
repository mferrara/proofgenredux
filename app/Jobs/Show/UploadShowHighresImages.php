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

    public int $timeout = 1800;

    public function __construct(string $show_id)
    {
        $this->show_id = $show_id;
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
