<?php

namespace App\Jobs\ShowClass;

use App\Proofgen\ShowClass;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImportPhotos implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $show;
    public string $class;
    public int $tries = 1;

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
        $count = $show_class->processPendingImages();
        if($count) {
            Log::info('Queued '.$count.' proofs to import for '.$this->show.' '.$this->class);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::debug(self::class.' failed for '.$this->show.' -> '.$this->class);
        Log::debug(self::class.' failed: '.$exception->getMessage().' in '.$exception->getFile().':'.$exception->getLine());
    }
}
