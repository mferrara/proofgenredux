<?php

namespace App\Jobs\ShowClass;

use App\Jobs\Ferraraphoto\EnsureFerraraphotoShow;
use App\Models\ShowClass;
use App\Services\AutomaticUploadSettings;
use App\Services\Delivery\DeliveryTargetException;
use App\Services\Delivery\DeliveryTargetResolver;
use App\Services\Ferraraphoto\FerraraphotoApiClient;
use App\Services\Ferraraphoto\WebsiteShowMissingException;
use App\Services\PathResolver;
use App\Services\Storage\ShowProfileBinder;
use App\Services\Storage\StorageProfileResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Shared automatic/manual delivery on the single uploads worker.
 * Later generation completions must remain queued, so this job is not unique.
 * Each run rechecks ready output kinds and transfer stamps before copying.
 */
class DeliverClassOutputs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /**
     * Derived in config/proofgen.php: the legacy-local path runs proofs +
     * web + highres synchronously inside the UploadDerivedFiles step, so the
     * budget covers three rsync transfers plus overhead.
     */
    public ?int $timeout = null;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $classId,
        public bool $automatic = false,
        public array $kinds = ['proofs', 'web', 'highres'],
    ) {
        $this->timeout = (int) config('proofgen.uploads.derived_job_timeout');
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
     * Execute the ordered delivery chain for this class.
     */
    public function handle(): void
    {
        if ($this->automatic && ! app(AutomaticUploadSettings::class)->enabled()) {
            return;
        }

        $class = ShowClass::findOrFail($this->classId);
        $kinds = $this->kinds;
        if ($this->automatic) {
            // A completed proof batch must not rsync web/highres files that
            // another generation worker is still writing.
            $columns = [
                'proofs' => 'proofs_generated_at',
                'web' => 'web_image_generated_at',
                'highres' => 'highres_image_generated_at',
            ];
            $kinds = array_values(array_filter($kinds, fn (string $kind) => ! $class->photos()->whereNull($columns[$kind])->exists()
            ));

            // Every generation kind announces its own completion, so one class
            // produces up to three automatic runs. When an earlier run already
            // uploaded everything that is ready, the later ones stop here rather
            // than repeat the website handshake, the SSH sessions, and the
            // metadata push for nothing.
            $uploadedColumns = [
                'proofs' => 'proofs_uploaded_at',
                'web' => 'web_image_uploaded_at',
                'highres' => 'highres_image_uploaded_at',
            ];
            $kinds = array_values(array_filter($kinds, fn (string $kind) => $class->photos()
                ->whereNotNull($columns[$kind])
                ->whereNull($uploadedColumns[$kind])
                ->exists()));
        }
        if ($kinds === [] || ! $class->photos()->exists()) {
            return;
        }

        // A show missing on the website, or a changed/unusable destination,
        // cannot be fixed by retrying: fail without burning the backoff schedule.
        try {
            // 1. Make sure the show/classes and the pinned profile exist remotely.
            //    No-op for legacy-local storage without an API token.
            (new EnsureFerraraphotoShow($class->show_id))->handle(
                app(FerraraphotoApiClient::class),
                app(ShowProfileBinder::class),
            );

            // 2. Confirm with Gallery where this show's rsync delivery goes,
            //    once per run.
            $class->load('show.storageProfile');
            if ($class->show->storageProfile?->isLegacyLocal()) {
                app(DeliveryTargetResolver::class)->refresh($class->show);
            }
        } catch (WebsiteShowMissingException|DeliveryTargetException $exception) {
            $retryable = $exception instanceof DeliveryTargetException && $exception->retryable;

            if (! $retryable && $this->job) {
                $this->fail($exception);

                return;
            }

            throw $exception;
        }

        // 3. Transfer the derived files (legacy rsync or pinned-profile copies).
        //    Throws on failure so the metadata push below is never reached.
        (new UploadDerivedFiles($this->classId, $kinds))->handle(
            app(StorageProfileResolver::class),
            app(PathResolver::class),
        );

        // 4. Push photo metadata. Same legacy-local/no-token skip rule as step 1.
        (new PushPhotoMetadata($this->classId))->handle(app(FerraraphotoApiClient::class));

        Log::info('Delivered derived outputs for '.$this->classId.'.');
    }
}
