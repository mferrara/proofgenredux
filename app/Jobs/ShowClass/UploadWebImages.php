<?php

namespace App\Jobs\ShowClass;

use App\Models\ShowClass;
use App\Services\FerraraphotoTargetVerifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class UploadWebImages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $show;

    public string $class;

    public int $tries = 5;

    /**
     * Derived in config/proofgen.php (one rsync transfer + overhead).
     */
    public ?int $timeout = null;

    /**
     * Create a new job instance.
     */
    /**
     * @param  int|null  $limit  Upload at most this many photos in this run; null = the whole class.
     */
    public function __construct(string $show, string $class, public ?int $limit = null)
    {
        $this->show = $show;
        $this->class = $class;
        $this->timeout = (int) config('proofgen.uploads.single_job_timeout');
        $this->onConnection((string) config('proofgen.uploads.connection'));
        $this->onQueue((string) config('proofgen.uploads.queue'));
    }

    /**
     * Seconds to wait before each retry; the last value repeats for any extra attempt.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return array_values((array) config('proofgen.uploads.backoff'));
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $showClass = ShowClass::find($this->show.'_'.$this->class);

        $status = app(FerraraphotoTargetVerifier::class)->verifyClassThrottled($showClass);
        if (! $status['web_images']['exists'] && ! $status['web_images']['error']) {
            Log::debug('UploadWebImages: remote web_images directory does not exist yet for '.$this->show.'/'.$this->class.' — rsync will create it.');
        }

        $web_uploaded = $showClass->webImageUploads($this->limit);
        if (count($web_uploaded)) {
            Log::info('Uploaded '.count($web_uploaded).' web images for '.$this->show.' '.$this->class);
        } else {
            Log::info('No web images to upload for '.$this->show.' '.$this->class);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::debug('UploadWebImages failed for '.$this->show.' -> '.$this->class);
        Log::debug('UploadWebImages failed: '.$exception->getMessage().' in '.$exception->getFile().':'.$exception->getLine());

        $verifier = app(FerraraphotoTargetVerifier::class);
        $verifier->forgetClassCache($this->show.'_'.$this->class);
        $status = $verifier->verifyClassThrottled($this->show.'_'.$this->class);
        if ($status['web_images']['error']) {
            Log::error('UploadWebImages likely cause: remote web_images disk is unreachable. '.$status['web_images']['error']);
        } elseif (! $status['web_images']['exists']) {
            Log::error('UploadWebImages likely cause: remote web_images directory '.$status['web_images']['path'].' does not exist on the ferraraphoto host.');
        }
    }
}
