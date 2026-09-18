<?php

namespace App\Services;

use App\Models\Show;
use App\Models\ShowClass;
use App\Services\Delivery\DeliveryTarget;
use App\Services\Delivery\DeliveryTargetDisks;
use App\Services\Delivery\DeliveryTargetResolver;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Pre-upload sanity check for ferraraphoto targets. Confirms that the remote
 * filesystem (whether via SFTP or the local-driver dev mode) actually has a
 * directory for the show/class about to receive an upload.
 *
 * This is advisory, not blocking. If the remote dir doesn't exist, the rsync
 * still proceeds (which creates it), but a warning surfaces in the UI so the
 * operator knows the ferraraphoto admin hasn't created the show yet — the most
 * common cause of "I uploaded but the customer can't find their photo."
 *
 * The three remote disks (`remote_proofs`, `remote_web_images`,
 * `remote_highres_images`) are configured by ConfigurationServiceProvider —
 * SFTP in normal mode, local in dev mode. Either way `Storage::disk()->exists()`
 * gives a one-call answer.
 */
class FerraraphotoTargetVerifier
{
    public const CACHE_TTL_SECONDS = 300; // 5 minutes — long enough to collapse a burst of upload jobs, short enough that admin "create show" lands quickly

    public function __construct(private ?PathResolver $pathResolver = null)
    {
        $this->pathResolver ??= app(PathResolver::class);
    }

    /**
     * Cached verifyClass for the upload jobs. The 5-minute TTL collapses bursts
     * of class uploads (proofs/web/highres run in chain) into one round-trip per
     * disk, while still being short enough that an operator who just created a
     * show on ferraraphoto sees the status flip quickly.
     */
    public function verifyClassThrottled(ShowClass|string $showClass): array
    {
        $key = $showClass instanceof ShowClass ? $showClass->id : $showClass;

        return Cache::remember(
            'ferraraphoto_verifier:class:'.$key,
            self::CACHE_TTL_SECONDS,
            fn () => $this->verifyClass($showClass),
        );
    }

    public function forgetClassCache(ShowClass|string $showClass): void
    {
        $key = $showClass instanceof ShowClass ? $showClass->id : $showClass;
        Cache::forget('ferraraphoto_verifier:class:'.$key);
    }

    /**
     * Verify all three remote target disks for a single show. Returns:
     *   ['proofs' => ['exists' => bool, 'path' => string, 'error' => ?string],
     *    'web_images' => [...], 'highres_images' => [...],
     *    'all_exist' => bool, 'any_exist' => bool, 'any_errored' => bool]
     */
    public function verifyShow(Show|string $show): array
    {
        // A Show model resolves its own delivery target (Gallery's handshake
        // when saved, else local settings; both honor the slug override). A
        // bare string (operator-typed lookup from the connector panel) has no
        // saved target, so it checks the local settings with the string as-is.
        $model = $show instanceof Show ? $show : (new Show)->forceFill(['id' => $show]);

        return $this->summarize($this->checkTarget($model));
    }

    /**
     * Verify all three remote target disks for a single class. Same return shape
     * as verifyShow().
     */
    public function verifyClass(ShowClass|string $showClass): array
    {
        // Resolve the actual ShowClass row for both forms. The composite
        // show_class_id cannot be split on underscores: the show id and the class
        // name may each contain them, and a wrong-prefix split could point at a
        // different, existing show. A missing class is reported as an explicit
        // unresolved target, never guessed.
        $model = $showClass instanceof ShowClass ? $showClass : ShowClass::find($showClass);

        if ($model === null) {
            $display = is_string($showClass) ? $showClass : $showClass->id;

            return $this->summarize([
                'proofs' => ['exists' => false, 'path' => '', 'error' => 'Class not found: '.$display],
                'web_images' => ['exists' => false, 'path' => '', 'error' => 'Class not found: '.$display],
                'highres_images' => ['exists' => false, 'path' => '', 'error' => 'Class not found: '.$display],
            ]);
        }

        // The show's delivery target honors both Gallery's handshake and the
        // ferraraphoto_slug override.
        $showModel = $model->show ?? (new Show)->forceFill(['id' => $model->show_id]);

        return $this->summarize($this->checkTarget($showModel, $model->name));
    }

    /**
     * @return array<string, array{exists: bool, path: string, error: ?string}>
     */
    private function checkTarget(Show $show, string $classFolder = ''): array
    {
        $target = app(DeliveryTargetResolver::class)->current($show);
        $entries = [];

        foreach (DeliveryTarget::KINDS as $kind) {
            try {
                [$disk, $path] = app(DeliveryTargetDisks::class)->locate($target, $kind, $classFolder);
                $entries[$kind] = $this->checkDisk($disk, $path);
            } catch (Throwable $e) {
                $entries[$kind] = ['exists' => false, 'path' => '', 'error' => $e->getMessage()];
            }
        }

        return $entries;
    }

    private function checkDisk(Filesystem $disk, string $path): array
    {
        try {
            $exists = $disk->exists($path);

            return ['exists' => $exists, 'path' => $path, 'error' => null];
        } catch (Throwable $e) {
            // SFTP can throw on connection/auth failures; treat as "unknown" rather than absent.
            return ['exists' => false, 'path' => $path, 'error' => $e->getMessage()];
        }
    }

    private function summarize(array $entries): array
    {
        $existsValues = array_map(fn ($e) => $e['exists'], $entries);
        $errorValues = array_map(fn ($e) => $e['error'] !== null, $entries);

        return $entries + [
            'all_exist' => ! in_array(false, $existsValues, true),
            'any_exist' => in_array(true, $existsValues, true),
            'any_errored' => in_array(true, $errorValues, true),
        ];
    }
}
