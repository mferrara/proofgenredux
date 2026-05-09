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

class UploadProofs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $show;

    public string $class;

    public int $tries = 5;

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
        $showClass = ShowClass::find($this->show.'_'.$this->class);

        // Pre-flight: throttled (5min cache) check that the remote ferraraphoto disks
        // have a directory for this class. Missing target isn't fatal — rsync will
        // create the dir — but it's the most common cause of "I uploaded but the
        // customer can't find their photo," so we log loudly.
        $status = app(FerraraphotoTargetVerifier::class)->verifyClassThrottled($showClass);
        if (! $status['proofs']['exists'] && ! $status['proofs']['error']) {
            Log::warning('UploadProofs: remote proofs directory does not exist yet for '.$this->show.'/'.$this->class.' — rsync will create it, but ferraraphoto admin may need to "Import Classes" before the public site can serve it.');
        }

        $uploaded = $showClass->proofUploads();
        if (count($uploaded)) {
            Log::info('Uploaded '.count($uploaded).' proofs for '.$this->show.' '.$this->class);
        } else {
            Log::info('No proofs to upload for '.$this->show.' '.$this->class);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::debug('UploadProofs failed for '.$this->show.' -> '.$this->class);
        Log::debug('UploadProofs failed: '.$exception->getMessage().' in '.$exception->getFile().':'.$exception->getLine());

        // Diagnostic: re-check the verifier (uncached) to surface the most common cause.
        $verifier = app(FerraraphotoTargetVerifier::class);
        $verifier->forgetClassCache($this->show.'_'.$this->class);
        $status = $verifier->verifyClassThrottled($this->show.'_'.$this->class);
        if ($status['proofs']['error']) {
            Log::error('UploadProofs likely cause: remote proofs disk is unreachable. '.$status['proofs']['error']);
        } elseif (! $status['proofs']['exists']) {
            Log::error('UploadProofs likely cause: remote proofs directory '.$status['proofs']['path'].' does not exist on the ferraraphoto host.');
        }
    }
}
