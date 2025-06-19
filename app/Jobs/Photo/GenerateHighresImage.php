<?php

namespace App\Jobs\Photo;

use App\Services\PhotoService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateHighresImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $photo_id;

    public string $highres_destination_path;

    /**
     * Create a new job instance.
     */
    public function __construct(string $photo_id, string $highres_destination_path)
    {
        $this->photo_id = $photo_id;
        $this->highres_destination_path = $highres_destination_path;
    }

    /**
     * Execute the job.
     *
     * @throws Exception
     */
    public function handle(): void
    {
        ini_set('memory_limit', '-1');
        $photoService = app(PhotoService::class);
        try {
            $photoService->generateHighresImage($this->photo_id, $this->highres_destination_path);
        } catch (Exception $e) {
            \Log::error('Error generating highres image: '.$e->getMessage());
            throw $e;
        }
    }
}
