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
use Illuminate\Support\Facades\Cache;
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
        $this->onQueue(self::queueFor($kinds));
    }

    /**
     * Proofs first, always: anything that includes proofs goes on the proofs
     * queue; web-only and highres-only deliveries wait on their own queues,
     * which the upload worker reaches only when no proofs are waiting.
     *
     * @param  array<int, string>  $kinds
     */
    public static function queueFor(array $kinds): string
    {
        return match (true) {
            $kinds === ['highres'] => (string) config('proofgen.uploads.highres_queue'),
            $kinds === ['web'] => (string) config('proofgen.uploads.web_queue'),
            default => (string) config('proofgen.uploads.queue'),
        };
    }

    /**
     * One delivery per kind, each on its own queue. This is how every caller
     * should start a delivery: a single all-kinds job would make a class's
     * highres go up before the next class's proofs.
     *
     * @param  array<int, string>  $kinds
     */
    public static function dispatchByPriority(string $classId, bool $automatic = false, array $kinds = ['proofs', 'web', 'highres']): void
    {
        foreach ($kinds as $kind) {
            self::dispatch($classId, $automatic, [$kind]);
        }
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

        $bulk = $kinds === ['web'] || $kinds === ['highres'];

        // Web and highres go up one photo per job, so the per-class preamble
        // (sync the show and every class with the website, confirm the
        // destination) must not be paid per photo. It stays good for a while
        // after any delivery of this class ran it; proofs always run it.
        $skipPreamble = $bulk && Cache::has('delivery-preamble:'.$this->classId);

        // Cheapest check first: on a slow connection highres steps aside
        // before anything talks to the website.
        if ($kinds === ['highres'] && $this->waitForBetterBandwidth()) {
            return;
        }

        if (! $skipPreamble && ! $this->runPreamble($class)) {
            return;
        }

        $waitingIdsBefore = $bulk ? $this->waitingToUpload($class, $kinds[0]) : [];
        $waitingBefore = count($waitingIdsBefore);
        $startedAt = microtime(true);

        (new UploadDerivedFiles($this->classId, $kinds, $bulk ? (int) config('proofgen.uploads.batch_size') : null))->handle(
            app(StorageProfileResolver::class),
            app(PathResolver::class),
        );

        $waitingIdsAfter = $bulk ? $this->waitingToUpload($class, $kinds[0]) : [];
        $waitingAfter = count($waitingIdsAfter);
        if ($bulk) {
            $this->recordThroughput($class, $kinds[0], $waitingBefore - $waitingAfter, microtime(true) - $startedAt);
        }

        // 4. Push photo metadata. Same legacy-local/no-token skip rule as step 1.
        //    A one-photo web/highres job tells the website about that photo
        //    only, not the whole class again.
        // Exactly the photos this job moved from "waiting" to "uploaded".
        $justUploaded = $bulk ? array_values(array_diff($waitingIdsBefore, $waitingIdsAfter)) : null;

        if ($justUploaded !== []) {
            (new PushPhotoMetadata($this->classId, $justUploaded))->handle(app(FerraraphotoApiClient::class));
        }

        // One line per photo would drown the log; whole-class deliveries stay at info.
        Log::log($bulk ? 'debug' : 'info', 'Delivered derived outputs for '.$this->classId.'.', ['kinds' => $kinds]);

        // More of this class is waiting: go to the back of the same queue, so
        // the worker looks for proofs (and other classes) before continuing.
        // A batch that uploaded nothing must not loop forever.
        if ($bulk && $waitingAfter > 0 && $waitingAfter < $waitingBefore) {
            self::dispatch($this->classId, $this->automatic, $kinds);
        }
    }

    /**
     * Steps 1 and 2: the show and classes exist on the website, and the website
     * confirmed where this show's files go. False = the job was failed for good
     * (a show missing on the website, or a changed/unusable destination, cannot
     * be fixed by retrying, so the backoff schedule is not burned on it).
     */
    private function runPreamble(ShowClass $class): bool
    {
        try {
            // 1. Make sure the show/classes and the pinned profile exist remotely.
            //    No-op for legacy-local storage without an API token.
            (new EnsureFerraraphotoShow($class->show_id, $class->id))->handle(
                app(FerraraphotoApiClient::class),
                app(ShowProfileBinder::class),
            );

            // 2. Confirm with Gallery where this show's rsync delivery goes.
            $class->load('show.storageProfile');
            if ($class->show->storageProfile?->isLegacyLocal()) {
                app(DeliveryTargetResolver::class)->refresh($class->show);
            }
        } catch (WebsiteShowMissingException|DeliveryTargetException $exception) {
            $retryable = $exception instanceof DeliveryTargetException && $exception->retryable;

            if (! $retryable && $this->job) {
                $this->fail($exception);

                return false;
            }

            throw $exception;
        }

        Cache::put('delivery-preamble:'.$this->classId, true, (int) config('proofgen.uploads.preamble_seconds'));

        return true;
    }

    /**
     * @return array<int, string> ids of photos generated but not yet uploaded for this kind
     */
    private function waitingToUpload(ShowClass $class, string $kind): array
    {
        [$generated, $uploaded] = [
            'web' => ['web_image_generated_at', 'web_image_uploaded_at'],
            'highres' => ['highres_image_generated_at', 'highres_image_uploaded_at'],
        ][$kind];

        return $class->photos()->whereNotNull($generated)->whereNull($uploaded)->pluck('id')->all();
    }

    /**
     * A rolling estimate of the uplink, measured only on web/highres batches:
     * proofs are so small that per-file overhead hides the real speed.
     */
    private function recordThroughput(ShowClass $class, string $kind, int $photos, float $seconds): void
    {
        if ($photos <= 0 || $seconds <= 0.5) {
            return;
        }

        $directory = rtrim((string) config('proofgen.fullsize_home_dir'), '/').'/'.($kind === 'web' ? $class->web_images_path : $class->highres_images_path);
        $sample = glob($directory.'/*.jpg') ?: [];
        $averageBytes = $sample === [] ? 0 : array_sum(array_map('filesize', array_slice($sample, 0, 20))) / min(20, count($sample));
        $kbps = ($photos * $averageBytes * 8 / 1000) / $seconds;

        if ($kbps > 0) {
            $previous = Cache::get('uploads.throughput');
            $smoothed = is_array($previous) ? 0.6 * $previous['kbps'] + 0.4 * $kbps : $kbps;
            Cache::put('uploads.throughput', ['kbps' => $smoothed, 'at' => now()->timestamp], 3600);
        }
    }

    /**
     * On a bad connection highres only gets in the way of what people are
     * waiting for. Put it back and try later; an old measurement is ignored,
     * so the next attempt runs a batch and measures again.
     */
    private function waitForBetterBandwidth(): bool
    {
        $minimum = (int) config('proofgen.uploads.highres_min_kbps');
        $measured = Cache::get('uploads.throughput');
        $retry = (int) config('proofgen.uploads.highres_retry_seconds');

        if ($minimum <= 0 || ! is_array($measured) || $measured['at'] < now()->timestamp - $retry || $measured['kbps'] >= $minimum) {
            return false;
        }

        Log::info('Highres upload for '.$this->classId.' postponed: the connection is slow ('.round($measured['kbps']).' kbps).');
        self::dispatch($this->classId, $this->automatic, $this->kinds)->delay($retry);

        return true;
    }
}
