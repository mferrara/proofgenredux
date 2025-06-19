<?php

namespace App\Jobs\ShowClass;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class UploadWebImages implements ShouldQueue
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
        $web_uploaded = $showClass->webImageUploads();
        if (count($web_uploaded)) {
            Log::info('Uploaded '.count($web_uploaded).' web images for '.$this->show.' '.$this->class);
        } else {
            Log::info('No web images to upload for '.$this->show.' '.$this->class);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::debug('UploadWebImages failed for '.$this->show.' -> '.$this->class);
        Log::debug('UploadWebImages failed: '.$exception->getMessage().' in '.$exception->getFile().':'.$exception->getLine());
    }
}
