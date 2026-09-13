<?php

namespace App\Jobs\Photo;

use App\Services\PhotoService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateHighresImage implements ShouldBeUnique, ShouldQueue
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
            Log::error('Error generating highres image: '.$e->getMessage());
            throw $e;
        }
    }

    /** One job per photo/output type, held through processing and retries. */
    public function uniqueId(): string
    {
        return $this->photo_id;
    }
}
