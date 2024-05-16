<?php

namespace App\Jobs\Photo;

use App\Proofgen\Image;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateThumbnails implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $photo_path;
    public string $proofs_destination_path;

    /**
     * Create a new job instance.
     */
    public function __construct(string $photo_path, string $proofs_destination_path)
    {
        $this->photo_path = $photo_path;
        $this->proofs_destination_path = $proofs_destination_path;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Image::createThumbnails($this->photo_path, $this->proofs_destination_path);
    }
}
