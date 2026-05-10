<?php

namespace App\Livewire;

use App\Models\StorageProfile;
use App\Services\Storage\ProfileAutoDetector;
use App\Services\Storage\StorageProfileHealthCheck;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Throwable;

class StorageProfilesComponent extends Component
{
    public ?string $pendingWritableProfileId = null;

    public function refreshFromEnv(): void
    {
        try {
            $detected = app(ProfileAutoDetector::class)->detectFromEnv();

            Flux::toast(
                text: count($detected).' storage profile(s) detected from .env.',
                heading: 'Storage profiles refreshed',
                variant: 'success',
                position: 'top right',
            );
        } catch (Throwable $exception) {
            Flux::toast(
                text: $exception->getMessage(),
                heading: 'Refresh failed',
                variant: 'danger',
                position: 'top right',
            );
        }
    }

    public function setActive(string $profileId): void
    {
        $profile = StorageProfile::query()->findOrFail($profileId);

        if (! $profile->is_writable) {
            Flux::toast(
                text: $profile->label.' is read-only.',
                heading: 'Profile not writable',
                variant: 'danger',
                position: 'top right',
            );

            return;
        }

        DB::transaction(function () use ($profile): void {
            StorageProfile::query()->where('is_active', true)->update(['is_active' => false]);
            $profile->forceFill(['is_active' => true])->save();
        });

        Flux::toast(
            text: $profile->label.' is now the active writable profile.',
            heading: 'Active profile updated',
            variant: 'success',
            position: 'top right',
        );
    }

    public function confirmWritableToggle(string $profileId): void
    {
        $this->pendingWritableProfileId = $profileId;
        Flux::modal('confirm-writable-toggle')->show();
    }

    public function toggleWritable(): void
    {
        if (! $this->pendingWritableProfileId) {
            Flux::modal('confirm-writable-toggle')->close();

            return;
        }

        $profile = StorageProfile::query()->findOrFail($this->pendingWritableProfileId);

        if ($profile->is_active && $profile->is_writable) {
            $this->pendingWritableProfileId = null;
            Flux::modal('confirm-writable-toggle')->close();
            Flux::toast(
                text: 'Set another writable profile active before marking '.$profile->label.' read-only.',
                heading: 'Active profile unchanged',
                variant: 'danger',
                position: 'top right',
            );

            return;
        }

        $profile->forceFill(['is_writable' => ! $profile->is_writable])->save();
        $this->pendingWritableProfileId = null;

        Flux::modal('confirm-writable-toggle')->close();
        Flux::toast(
            text: $profile->label.' is now '.($profile->is_writable ? 'writable.' : 'read-only.'),
            heading: 'Profile updated',
            variant: 'success',
            position: 'top right',
        );
    }

    public function statusColor(string $status): string
    {
        return match ($status) {
            'ok' => 'emerald',
            'drift', 'missing_credentials' => 'amber',
            default => 'rose',
        };
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'ok' => 'Healthy',
            'missing_credentials' => 'Missing credentials',
            'drift' => 'Drift',
            default => 'Unreachable',
        };
    }

    public function render()
    {
        $profiles = StorageProfile::query()
            ->withCount('shows')
            ->orderByDesc('is_active')
            ->orderBy('label')
            ->get();

        $healthCheck = app(StorageProfileHealthCheck::class);
        $health = $profiles->mapWithKeys(fn (StorageProfile $profile) => [
            $profile->id => $healthCheck->check($profile),
        ])->all();

        $activeProfile = $profiles->firstWhere('is_active', true);
        $pendingWritableProfile = $profiles->firstWhere('id', $this->pendingWritableProfileId);

        return view('livewire.storage-profiles-component', [
            'profiles' => $profiles,
            'health' => $health,
            'activeProfile' => $activeProfile,
            'pendingWritableProfile' => $pendingWritableProfile,
        ])->title('Storage Profiles - Proofgen');
    }
}
