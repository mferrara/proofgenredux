<?php

namespace App\Jobs\Photo;

use App\Jobs\ShowClass\UploadProofs;
use App\Proofgen\Image;
use App\Proofgen\ShowClass;
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
        // Check class, if no more images pending proofs we'll queue up the upload job
        $image = new Image($this->photo_path);
        $show_class = new ShowClass($image->show, $image->class);
        $pending_proofs = $show_class->getImagesPendingProofing();
        if (count($pending_proofs) === 0) {
            UploadProofs::dispatch($image->show, $image->class);
        }
    }
}
