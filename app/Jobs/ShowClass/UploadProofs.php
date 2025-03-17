<?php

namespace App\Jobs\ShowClass;

use App\Proofgen\ShowClass;
use App\Services\PathResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class UploadProofs implements ShouldQueue
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
        $pathResolver = app(PathResolver::class);
        $show_class = new ShowClass($this->show, $this->class, $pathResolver);
        $uploaded = $show_class->uploadPendingProofs();
        if(count($uploaded)) {
            Log::info('Uploaded '.count($uploaded).' proofs for '.$this->show.' '.$this->class);
        }
        $web_uploaded = $show_class->uploadPendingWebImages();
        if(count($web_uploaded)) {
            Log::info('Uploaded '.count($web_uploaded).' web images for '.$this->show.' '.$this->class);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::debug('UploadProofs failed for '.$this->show.' -> '.$this->class);
        Log::debug('UploadProofs failed: '.$exception->getMessage().' in '.$exception->getFile().':'.$exception->getLine());
    }
}
