<?php

namespace App\Jobs\ShowClass;

use App\Models\ShowClass;
use App\Services\Ferraraphoto\FerraraphotoApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PushPhotoMetadata implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public string $classId) {}

    public function handle(FerraraphotoApiClient $api): void
    {
        $class = ShowClass::with('show.storageProfile')->findOrFail($this->classId);
        $profile = $class->show->storageProfile;

        if (! $profile) {
            throw new RuntimeException('Show '.$class->show_id.' is not pinned to a storage profile.');
        }

        if ($profile->isLegacyLocal() && blank(config('proofgen.ferraraphoto.api_token'))) {
            Log::info('Skipped ferraraphoto photo metadata sync for legacy-local profile because no API token is configured.', [
                'show_class_id' => $class->id,
            ]);

            return;
        }

        $class->photos()
            ->with('metadata')
            ->orderBy('id')
            ->chunk(500, function (Collection $photos) use ($api, $class): void {
                $api->upsertPhotos($class, $photos);
            });
    }
}
