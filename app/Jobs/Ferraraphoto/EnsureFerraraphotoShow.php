<?php

namespace App\Jobs\Ferraraphoto;

use App\Models\Show;
use App\Services\Ferraraphoto\FerraraphotoApiClient;
use App\Services\Ferraraphoto\FerraraphotoApiException;
use App\Services\Ferraraphoto\WebsiteShowMissingException;
use App\Services\Storage\ShowProfileBinder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EnsureFerraraphotoShow implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public string $showId) {}

    public function handle(FerraraphotoApiClient $api, ShowProfileBinder $binder): void
    {
        $show = Show::with(['classes', 'storageProfile'])->findOrFail($this->showId);
        $profile = $binder->pin($show);

        if ($profile->isLegacyLocal() && blank(config('proofgen.ferraraphoto.api_token'))) {
            Log::info('Skipped ferraraphoto show sync for legacy-local profile because no API token is configured.', [
                'show_id' => $show->id,
            ]);

            return;
        }

        $show->loadMissing(['classes', 'storageProfile']);

        // Both applications seed legacy-local. Its sentinel fingerprint and
        // per-kind filesystem roots are not a custom profile registration.
        if (! $profile->isLegacyLocal()) {
            $api->upsertStorageProfile($profile);
        }
        $this->syncShow($api, $show);

        foreach ($show->classes as $class) {
            $api->upsertClass($class);
        }
    }

    /**
     * Shows are created on the website. Once Gallery offers its show list,
     * Proofgen only ever updates a show that already exists there; an older
     * Gallery without the list keeps creating shows through the API as before.
     */
    private function syncShow(FerraraphotoApiClient $api, Show $show): void
    {
        $slug = $show->ferraraphoto_slug;

        if ($api->readShow($slug) === null && $api->listShows(limit: 1) !== null) {
            throw WebsiteShowMissingException::forSlug($slug);
        }

        try {
            $api->upsertShow($show);
        } catch (FerraraphotoApiException $exception) {
            if ($exception->status === 409 && $exception->apiCode === 'show_creation_disabled') {
                throw WebsiteShowMissingException::forSlug($slug, $exception);
            }

            throw $exception;
        }
    }
}
