<?php

namespace App\Jobs\ShowClass;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class UploadHighresImages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $show;

    public string $class;

    public int $tries = 5;

    /**
     * Create a new job instance.
     */
    public function __construct(string $show, string $class)
    {
        $this->show = $show;
        $this->class = $class;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $showClass = \App\Models\ShowClass::find($this->show.'_'.$this->class);
        $highres_uploaded = $showClass->highresImageUploads();
        if (count($highres_uploaded)) {
            Log::info('Uploaded '.count($highres_uploaded).' highres images for '.$this->show.' '.$this->class);
        } else {
            Log::info('No highres images to upload for '.$this->show.' '.$this->class);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::debug('UploadHighresImages failed for '.$this->show.' -> '.$this->class);
        Log::debug('UploadHighresImages failed: '.$exception->getMessage().' in '.$exception->getFile().':'.$exception->getLine());
    }
}
