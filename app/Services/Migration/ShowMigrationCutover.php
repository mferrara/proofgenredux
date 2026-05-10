<?php

namespace App\Services\Migration;

use App\Jobs\Ferraraphoto\EnsureFerraraphotoShow;
use App\Jobs\ShowClass\PushPhotoMetadata;
use App\Models\MigrationInventory;
use App\Models\Photo;
use App\Models\PhotoIssue;
use App\Models\Show;
use App\Models\StorageProfile;
use App\Services\Ferraraphoto\FerraraphotoApiClient;
use App\Services\Storage\StorageProfileHealthCheck;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ShowMigrationCutover
{
    public function __construct(
        private readonly StorageProfileHealthCheck $health,
        private readonly FerraraphotoApiClient $api,
    ) {}

    /**
     * @return array{ok: bool, errors: list<string>, active_profile: ?StorageProfile}
     */
    public function preflight(Show $show): array
    {
        $errors = [];
        $activeProfile = $this->activeCloudProfile();

        if (! $activeProfile) {
            $errors[] = 'No active writable cloud storage profile is configured.';
        }

        $totalRows = MigrationInventory::query()
            ->where('show_slug', $show->ferraraphoto_slug)
            ->count();

        if ($totalRows === 0) {
            $errors[] = 'No migration inventory rows exist for this show.';
        }

        $unverifiedRows = MigrationInventory::query()
            ->where('show_slug', $show->ferraraphoto_slug)
            ->where('status', '!=', MigrationInventory::STATUS_VERIFIED)
            ->count();

        if ($unverifiedRows > 0) {
            $errors[] = $unverifiedRows.' migration inventory rows are not verified.';
        }

        $missingSources = PhotoIssue::query()
            ->where('show_id', $show->id)
            ->where('issue_type', PhotoIssue::TYPE_MIGRATION_SOURCE_MISSING)
            ->where('status', PhotoIssue::STATUS_OPEN)
            ->count();

        if ($missingSources > 0) {
            $errors[] = $missingSources.' photos have missing legacy migration sources.';
        }

        if ($activeProfile) {
            $localHealth = $this->health->check($activeProfile);
            if (($localHealth['status'] ?? null) !== 'ok') {
                $errors[] = 'Active storage profile health check failed: '.($localHealth['message'] ?? $localHealth['status'] ?? 'unknown');
            }

            try {
                $remoteHealth = $this->api->profileHealth($activeProfile->id);
                if (($remoteHealth['status'] ?? null) !== 'ok') {
                    $errors[] = 'Ferraraphoto storage profile health check failed: '.($remoteHealth['message'] ?? $remoteHealth['status'] ?? 'unknown');
                }
            } catch (Throwable $exception) {
                $errors[] = 'Ferraraphoto API is not reachable: '.$exception->getMessage();
            }
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'active_profile' => $activeProfile,
        ];
    }

    public function cutover(Show $show): array
    {
        $preflight = $this->preflight($show);
        if (! $preflight['ok'] || ! $preflight['active_profile']) {
            throw new RuntimeException(implode(' ', $preflight['errors']));
        }

        $activeProfile = $preflight['active_profile'];
        $updatedPhotos = 0;

        DB::transaction(function () use ($show, $activeProfile, &$updatedPhotos): void {
            $show->forceFill(['storage_profile_id' => $activeProfile->id])->save();
            $show->loadMissing('classes.photos');

            foreach ($show->classes as $class) {
                foreach ($class->photos as $photo) {
                    $photo->forceFill($this->objectKeysFor($show, $class->name, $photo))->save();
                    $updatedPhotos++;
                }
            }
        });

        $show->refresh()->load('classes');
        $jobs = [new EnsureFerraraphotoShow($show->id)];
        foreach ($show->classes as $class) {
            $jobs[] = new PushPhotoMetadata($class->id);
        }

        Bus::chain($jobs)->dispatch();

        return [
            'profile_id' => $activeProfile->id,
            'photos_updated' => $updatedPhotos,
            'jobs_dispatched' => count($jobs),
        ];
    }

    /**
     * @return array{ok: bool, local_photo_count: int, remote_photo_count: int}
     */
    public function pollFerraraphotoVerification(Show $show): array
    {
        $remoteShow = $this->api->readShow($show->ferraraphoto_slug);
        $remotePhotoCount = $this->remotePhotoCount($remoteShow);
        $localPhotoCount = $show->photos()->count();

        return [
            'ok' => $remotePhotoCount === $localPhotoCount,
            'local_photo_count' => $localPhotoCount,
            'remote_photo_count' => $remotePhotoCount,
        ];
    }

    private function objectKeysFor(Show $show, string $classNumber, Photo $photo): array
    {
        $rows = MigrationInventory::query()
            ->where('show_slug', $show->ferraraphoto_slug)
            ->where('class_number', $classNumber)
            ->where('proof_number', $photo->proof_number)
            ->where('status', MigrationInventory::STATUS_VERIFIED)
            ->get()
            ->keyBy('content_type');

        return [
            'proof_thm_key' => $rows->get(MigrationInventory::CONTENT_PROOF_THM)?->target_object_key,
            'proof_std_key' => $rows->get(MigrationInventory::CONTENT_PROOF_STD)?->target_object_key,
            'web_image_key' => $rows->get(MigrationInventory::CONTENT_WEB_IMAGE)?->target_object_key,
            'high_res_image_key' => $rows->get(MigrationInventory::CONTENT_HIGH_RES_IMAGE)?->target_object_key,
        ];
    }

    private function activeCloudProfile(): ?StorageProfile
    {
        return StorageProfile::query()
            ->where('is_active', true)
            ->where('is_writable', true)
            ->where('id', '!=', StorageProfile::LEGACY_LOCAL_ID)
            ->first();
    }

    private function remotePhotoCount(?array $remoteShow): int
    {
        if ($remoteShow === null) {
            return 0;
        }

        if (isset($remoteShow['photo_count'])) {
            return (int) $remoteShow['photo_count'];
        }

        $classes = $remoteShow['classes'] ?? [];
        if (! is_array($classes)) {
            return 0;
        }

        return array_sum(array_map(fn ($class) => (int) ($class['photo_count'] ?? 0), $classes));
    }
}
