<?php

namespace App\Jobs\Photo;

use App\Services\PhotoService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateThumbnails implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $photo_id;

    public string $proofs_destination_path;

    /**
     * Create a new job instance.
     */
    public function __construct(string $photo_id, string $proofs_destination_path)
    {
        $this->photo_id = $photo_id;
        $this->proofs_destination_path = $proofs_destination_path;
    }

    /**
     * Execute the job.
     *
     * @throws Exception
     */
    public function handle(): void
    {
        $photoService = app(PhotoService::class);
        try {
            $photoService->generateThumbnails($this->photo_id, $this->proofs_destination_path, true);
        } catch (Exception $e) {
            \Log::error('Error generating thumbnails: '.$e->getMessage());
            throw $e;
        }
    }
}
