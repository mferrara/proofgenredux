<?php

namespace App\Jobs\Photo;

use App\Services\PhotoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ImportPhoto implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $image_path;

    public ?string $proof_number_override;

    /**
     * Create a new job instance. Pass a proof number only when the caller has already
     * decided which number to use (e.g. tests). Otherwise the resolver decides whether
     * to allocate one based on the source file's identity.
     */
    public function __construct(string $image_path, ?string $proof_number_override = null)
    {
        $this->image_path = $image_path;
        $this->proof_number_override = $proof_number_override;
    }

    /**
     * @throws \Exception
     */
    public function handle(): void
    {
        $photoService = app(PhotoService::class);
        $photoService->processPhoto($this->image_path, $this->proof_number_override, false);
    }
}
