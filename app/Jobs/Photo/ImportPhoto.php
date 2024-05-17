<?php

namespace App\Jobs\Photo;

use App\Proofgen\Image;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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
        $fullsize_image_path = $image_obj->processImage($this->proof_number, false);
        $proof_dest_path = '/proofs/'.$image_obj->show.'/'.$image_obj->class;
        $web_images_path = '/web_images/'.$image_obj->show.'/'.$image_obj->class;
        GenerateThumbnails::dispatch($fullsize_image_path, $proof_dest_path)->onQueue('thumbnails');
        GenerateWebImage::dispatch($fullsize_image_path, $web_images_path)->onQueue('thumbnails');
    }
}
