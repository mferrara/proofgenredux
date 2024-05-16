<?php

namespace App\Jobs\ShowClass;

use App\Proofgen\ShowClass;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UploadProofs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $show;
    public string $class;

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
        $show_class = new ShowClass($this->show, $this->class);
        $uploaded = $show_class->uploadPendingProofs();
        $web_uploaded = $show_class->uploadPendingWebImages();
    }
}
