<?php

namespace App\Jobs\Photo;

use App\Proofgen\Image;
use App\Services\PhotoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateWebImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $full_size_path;
    public string $web_destination_path;

    /**
     * Create a new job instance.
     */
    public function __construct(string $full_size_path, string $web_destination_path)
    {
        $this->full_size_path = $full_size_path;
        $this->web_destination_path = $web_destination_path;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $photoService = app(PhotoService::class);
        $photoService->generateWebImage($this->full_size_path, $this->web_destination_path);
    }
}
