<?php

namespace App\Services;

use App\Models\Show;
use App\Models\ShowClass;
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
        // When given a Show model use its ferraraphoto_slug accessor (which honors
        // the operator override). When given a bare string (operator-typed lookup
        // from the connector panel) use the string as-is.
        $slug = $show instanceof Show ? $show->ferraraphoto_slug : $show;
        $proofsPath = $this->pathResolver->normalizePath($this->pathResolver->getRemoteProofsPath($slug));
        $webPath = $this->pathResolver->normalizePath($this->pathResolver->getRemoteWebImagesPath($slug));
        $highresPath = $this->pathResolver->normalizePath($this->pathResolver->getRemoteHighresImagesPath($slug));

        return $this->summarize([
            'proofs' => $this->checkDisk('remote_proofs', $proofsPath),
            'web_images' => $this->checkDisk('remote_web_images', $webPath),
            'highres_images' => $this->checkDisk('remote_highres_images', $highresPath),
        ]);
    }

    /**
     * Verify all three remote target disks for a single class. Same return shape
     * as verifyShow().
     */
    public function verifyClass(ShowClass|string $showClass): array
    {
        if ($showClass instanceof ShowClass) {
            // Honor the show's ferraraphoto_slug override; falls back to id when null.
            $slug = $showClass->show?->ferraraphoto_slug ?? $showClass->show_id;
            $className = $showClass->name;
        } else {
            $parts = explode('_', $showClass, 2);
            if (count($parts) !== 2) {
                return $this->summarize([
                    'proofs' => ['exists' => false, 'path' => '', 'error' => 'Invalid class id: '.$showClass],
                    'web_images' => ['exists' => false, 'path' => '', 'error' => 'Invalid class id: '.$showClass],
                    'highres_images' => ['exists' => false, 'path' => '', 'error' => 'Invalid class id: '.$showClass],
                ]);
            }
            [$showId, $className] = $parts;
            // String form is operator-supplied — use it directly, no override lookup.
            $slug = $showId;
        }

        $proofsPath = $this->pathResolver->normalizePath($this->pathResolver->getRemoteProofsPath($slug, $className));
        $webPath = $this->pathResolver->normalizePath($this->pathResolver->getRemoteWebImagesPath($slug, $className));
        $highresPath = $this->pathResolver->normalizePath($this->pathResolver->getRemoteHighresImagesPath($slug, $className));

        return $this->summarize([
            'proofs' => $this->checkDisk('remote_proofs', $proofsPath),
            'web_images' => $this->checkDisk('remote_web_images', $webPath),
            'highres_images' => $this->checkDisk('remote_highres_images', $highresPath),
        ]);
    }

    private function checkDisk(string $disk, string $path): array
    {
        try {
            $exists = Storage::disk($disk)->exists($path);

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
