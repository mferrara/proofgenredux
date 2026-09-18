<?php

namespace App\Jobs\Ferraraphoto;

use App\Models\Show;
use App\Services\Ferraraphoto\FerraraphotoApiClient;
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
        $api->upsertShow($show);

        foreach ($show->classes as $class) {
            $api->upsertClass($class);
        }
    }
}
