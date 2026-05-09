<?php

namespace App\Livewire;

use App\Services\GraveyardService;
use Flux\Flux;
use Livewire\Component;
use Livewire\WithPagination;

class GraveyardComponent extends Component
{
    use WithPagination;

    public string $disk = 'fullsize';

    public int $perPage = 25;

    public int $purgeDays = 90;

    public ?string $pendingPurgeDisk = null;

    public function mount(): void
    {
        $this->purgeDays = app(GraveyardService::class)->agedDays();
    }

    public function selectDisk(string $disk): void
    {
        if (! in_array($disk, GraveyardService::DISKS, true)) {
            return;
        }

        $this->disk = $disk;
        $this->resetPage();
    }

    public function confirmPurge(string $disk): void
    {
        if (! in_array($disk, GraveyardService::DISKS, true)) {
            return;
        }

        $this->pendingPurgeDisk = $disk;
        Flux::modal('confirm-purge')->show();
    }

    public function purge(): void
    {
        $disk = $this->pendingPurgeDisk;
        $this->pendingPurgeDisk = null;

        if (! is_string($disk) || ! in_array($disk, GraveyardService::DISKS, true)) {
            Flux::modal('confirm-purge')->close();

            return;
        }

        $result = app(GraveyardService::class)->purgeOlderThan($disk, $this->purgeDays);

        Flux::modal('confirm-purge')->close();

        Flux::toast(
            text: sprintf(
                'Removed %d file(s), freed %s.',
                $result['deleted_count'],
                $this->formatBytes($result['freed_bytes']),
            ),
            heading: 'Graveyard purged',
            variant: $result['deleted_count'] > 0 ? 'success' : 'warning',
            position: 'top right',
        );
    }

    public function render()
    {
        $service = app(GraveyardService::class);
        $summary = $service->summary();
        $page = max(1, (int) $this->getPage());
        $offset = ($page - 1) * $this->perPage;

        $entries = $service->listEntries($this->disk, $this->perPage + 1, $offset);
        $hasMore = count($entries) > $this->perPage;
        if ($hasMore) {
            $entries = array_slice($entries, 0, $this->perPage);
        }

        return view('livewire.graveyard-component', [
            'summary' => $summary,
            'entries' => $entries,
            'page' => $page,
            'hasMore' => $hasMore,
            'agedDays' => $service->agedDays(),
        ])->title('Graveyard');
    }

    public function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        $i = 0;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return number_format($value, 2).' '.$units[$i];
    }
}
