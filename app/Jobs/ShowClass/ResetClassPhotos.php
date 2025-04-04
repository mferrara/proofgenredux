<?php

namespace App\Jobs\ShowClass;

use App\Models\Show;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ResetClassPhotos implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $show_id;
    public string $class;
    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(string $show_id, string $class)
    {
        $this->show_id = $show_id;
        $this->class = $class;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $show = Show::find($this->show_id);
        if (!$show) {
            Log::error(self::class.': Show not found: '.$this->show_id);
            return;
        }
        $show_class = $show->classes()->where('id', $show->name.'_'.$this->class)->first();
        if (!$show_class) {
            Log::error(self::class.': ShowClass not found: '.$this->show_id.'_'.$this->class);
            return;
        }

        $show_class->resetPhotos();
    }

    public function failed(?Throwable $exception): void
    {
        Log::debug(self::class.' failed for '.$this->show_id.' -> '.$this->class);
        Log::debug(self::class.' failed: '.$exception->getMessage().' in '.$exception->getFile().':'.$exception->getLine());
    }
}
