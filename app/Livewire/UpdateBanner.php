<?php

namespace App\Livewire;

use App\Services\UpdateNotice;
use App\Services\UpdateService;
use Flux\Flux;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

/** The thin "a new version is available" bar at the top of every page. */
class UpdateBanner extends Component
{
    /** `wire:init`: the git fetch happens after the page is on screen, at most every 30 minutes. */
    public function check(): void
    {
        try {
            app(UpdateNotice::class)->refresh();
        } catch (\Throwable $e) {
            Log::warning('Update check failed: '.$e->getMessage());
        }
    }

    public function remindLater(): void
    {
        app(UpdateNotice::class)->remindLater();
    }

    public function skipVersion(): void
    {
        $notice = app(UpdateNotice::class);
        if ($offer = $notice->offer()) {
            $notice->skip($offer['latest']);
        }
    }

    public function updateNow(): void
    {
        $result = app(UpdateService::class)->performUpdate();
        app(UpdateNotice::class)->forgetCheck();

        if ($result['success']) {
            Flux::toast(text: 'Proofgen is up to date. Reloading the page…', heading: 'Update complete', variant: 'success', position: 'top right');
            $this->js('setTimeout(() => window.location.reload(), 3000)');

            return;
        }

        Flux::toast(
            text: ($result['error'] ?? 'Unknown error').' Nothing was lost; open Settings → Services for the details.',
            heading: 'Update failed',
            variant: 'danger',
            position: 'top right',
            duration: 0,
        );
    }

    public function render()
    {
        return view('livewire.update-banner', ['offer' => app(UpdateNotice::class)->offer()]);
    }
}
