<?php

namespace App\Jobs\Photo;

use App\Proofgen\Image;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ImportPhoto implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $image_path;
    public string $proof_number;

    /**
     * Create a new job instance.
     */
    public function __construct(string $image_path, string $proof_number)
    {
        $this->image_path = $image_path;
        $this->proof_number = $proof_number;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $image_obj = new Image($this->image_path);
        $image_obj->processImage($this->proof_number, false);
    }
}
