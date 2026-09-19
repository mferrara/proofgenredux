<?php

namespace App\Livewire;

use App\Helpers\DirectoryNameValidator;
use App\Jobs\Cards\DumpCard;
use App\Models\Show;
use App\Proofgen\Utility;
use App\Services\Cards\CardDumper;
use App\Services\Cards\CardScanner;
use App\Services\Cards\CardVolume;
use App\Services\Cards\CardVolumeFinder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Card Reader: put a card in, say which class it is, press one button.
 *
 * The photographer now dumps his own cards between classes, so this replaces a
 * dedicated person's routine: two verified copies as fast as the drives allow,
 * imports started without waiting on them, and (when asked) the card emptied -
 * an empty card is how everyone knows it was safely dumped.
 */
class CardReaderComponent extends Component
{
    public string $show_id = '';

    public ?string $mountPoint = null;

    public string $mode = 'single';

    public string $classFolder = '';

    public string $newClassName = '';

    public int $gapMinutes = 5;

    /** @var array<int, array<int, int>> groups of image indexes (split mode) */
    public array $groups = [];

    /** @var array<int, string> group index => class folder */
    public array $groupClasses = [];

    public bool $importNow = true;

    public bool $clearCard = false;

    public bool $ejectWhenDone = true;

    public ?string $dumpId = null;

    public function mount(string $show_id): void
    {
        $this->show_id = $show_id;
        $this->gapMinutes = (int) config('proofgen.cards.gap_minutes', 5);
    }

    /**
     * Follows the remembered reader: when a card appears in it, that card is
     * selected without anyone choosing it again.
     */
    public function watchReader(): void
    {
        if ($this->dumping()) {
            return;
        }

        $finder = app(CardVolumeFinder::class);

        if ($this->mountPoint !== null && $finder->byMountPoint($this->mountPoint) === null) {
            $this->forgetCard();
        }

        if ($this->mountPoint === null && ($volume = $finder->inSlot($this->slotKey())) !== null) {
            $this->selectVolume($volume->mountPoint);
        }
    }

    public function selectVolume(string $mountPoint): void
    {
        $volume = app(CardVolumeFinder::class)->byMountPoint($mountPoint);

        if ($volume === null) {
            return;
        }

        Cache::forever('cards.slot_key', $volume->slotKey);
        $this->forgetCard();
        $this->mountPoint = $volume->mountPoint;

        if ($volume->readable) {
            Cache::put($this->scanKey($volume), app(CardScanner::class)->scan($volume), 3600);
            $this->regroup();
        }
    }

    public function rescan(): void
    {
        if ($this->mountPoint !== null) {
            $this->selectVolume($this->mountPoint);
        }
    }

    public function updatedGapMinutes(): void
    {
        $this->gapMinutes = max(1, min(240, $this->gapMinutes));
        $this->regroup();
    }

    public function regroup(): void
    {
        $this->groups = app(CardScanner::class)->groupByPauses($this->images(), $this->gapMinutes);
        $this->groupClasses = [];
    }

    /** Start a new class at this photo. */
    public function splitAt(int $imageIndex): void
    {
        foreach ($this->groups as $g => $indexes) {
            $position = array_search($imageIndex, $indexes, true);

            if ($position !== false && $position > 0) {
                array_splice($this->groups, $g, 1, [array_slice($indexes, 0, $position), array_slice($indexes, $position)]);
                $this->groupClasses = [];

                return;
            }
        }
    }

    /** Join this group to the one before it. */
    public function mergeUp(int $group): void
    {
        if ($group > 0 && isset($this->groups[$group])) {
            $this->groups[$group - 1] = array_merge($this->groups[$group - 1], $this->groups[$group]);
            array_splice($this->groups, $group, 1);
            $this->groupClasses = [];
        }
    }

    public function createClass(): void
    {
        $name = trim($this->newClassName);

        if (! DirectoryNameValidator::isValid($name)) {
            $this->addError('newClassName', DirectoryNameValidator::getValidationError($name).' Try: '.DirectoryNameValidator::suggestValidName($name));

            return;
        }

        Storage::disk('fullsize')->makeDirectory($this->show_id.'/'.$name);
        $this->resetErrorBag('newClassName');
        $this->newClassName = '';

        // The class just typed is the one wanted: select it, or when splitting,
        // give it to the first group that has no class yet.
        if ($this->mode === 'single') {
            $this->classFolder = $name;

            return;
        }

        foreach (array_keys($this->groups) as $g) {
            if (($this->groupClasses[$g] ?? '') === '') {
                $this->groupClasses[$g] = $name;

                return;
            }
        }
    }

    public function startDump(): void
    {
        $volume = $this->volume();
        $images = $this->images();

        if ($volume === null || $images === [] || $this->dumping()) {
            return;
        }

        $assignments = [];
        if ($this->mode === 'single') {
            if ($this->classFolder === '') {
                $this->addError('classFolder', 'Choose the class these photos belong to.');

                return;
            }
            $assignments[$this->classFolder] = array_column($images, 'path');
        } else {
            foreach ($this->groups as $g => $indexes) {
                $class = $this->groupClasses[$g] ?? '';
                if ($class === '') {
                    continue; // left on the card for another pass
                }
                foreach ($indexes as $index) {
                    $assignments[$class][] = $images[$index]['path'];
                }
            }

            if ($assignments === []) {
                $this->addError('classFolder', 'Choose a class for at least one group.');

                return;
            }
        }

        $this->resetErrorBag();
        $this->dumpId = (string) Str::uuid();

        DumpCard::dispatch($this->dumpId, $this->show_id, $volume->mountPoint, $volume->volumeUuid, $assignments, [
            'import' => $this->importNow,
            'clear' => $this->clearCard && app(CardDumper::class)->archiveUsable($this->show_id),
            // A card that still holds unassigned groups is not finished.
            'eject' => $this->ejectWhenDone && $this->allGroupsAssigned(),
        ]);
    }

    /** After a dump: back to waiting for the next card (or the rest of this one). */
    public function nextCard(): void
    {
        $this->dumpId = null;
        $this->classFolder = '';
        $this->rescan();
    }

    public function ejectNow(): void
    {
        $volume = $this->volume();

        if ($volume !== null && ! $this->dumping()) {
            Process::timeout(30)->run(['/usr/sbin/diskutil', 'eject', $volume->parentWholeDisk ?: $volume->mountPoint]);
            $this->forgetCard();
        }
    }

    public function render()
    {
        $finder = app(CardVolumeFinder::class);
        $progress = $this->dumpId ? Cache::get(DumpCard::progressKey($this->dumpId)) : null;

        return view('livewire.card-reader-component', [
            'volumes' => $finder->all(),
            'volume' => $this->volume(),
            'scan' => $this->scanResult(),
            'classes' => $this->classFolders(),
            'archiveUsable' => app(CardDumper::class)->archiveUsable($this->show_id),
            'progress' => $progress,
            'waiting' => $this->waitingFiles($progress),
            'dumping' => $this->dumping(),
        ]);
    }

    /**
     * Files of the running dump that are not copied yet, in copy order. The
     * first one is the file being copied now. Files are copied in the order
     * the job listed them, so the count done is all that is needed.
     *
     * @return array<int, array{class: string, name: string}>
     */
    private function waitingFiles(?array $progress): array
    {
        if (! $progress || ($progress['state'] ?? null) !== 'copying') {
            return [];
        }

        return array_slice(Cache::get(DumpCard::filesKey($this->dumpId), []), (int) $progress['done']);
    }

    private function allGroupsAssigned(): bool
    {
        if ($this->mode === 'single') {
            return true;
        }

        foreach (array_keys($this->groups) as $g) {
            if (($this->groupClasses[$g] ?? '') === '') {
                return false;
            }
        }

        return true;
    }

    private function dumping(): bool
    {
        $progress = $this->dumpId ? Cache::get(DumpCard::progressKey($this->dumpId)) : null;

        return $this->dumpId !== null && ! in_array($progress['state'] ?? 'queued', ['done', 'failed'], true);
    }

    private function forgetCard(): void
    {
        $this->mountPoint = null;
        $this->groups = [];
        $this->groupClasses = [];
        $this->mode = 'single';
    }

    private function slotKey(): ?string
    {
        return Cache::get('cards.slot_key');
    }

    private function volume(): ?CardVolume
    {
        return $this->mountPoint ? app(CardVolumeFinder::class)->byMountPoint($this->mountPoint) : null;
    }

    private function scanKey(CardVolume $volume): string
    {
        return 'card-scan:'.sha1($volume->mountPoint.'|'.$volume->volumeUuid);
    }

    /**
     * @return array{images: array<int, array<string, mixed>>, skipped: array<string, int>, total_bytes: int}|null
     */
    private function scanResult(): ?array
    {
        $volume = $this->volume();

        return $volume ? Cache::get($this->scanKey($volume)) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function images(): array
    {
        return $this->scanResult()['images'] ?? [];
    }

    /**
     * @return array<int, string>
     */
    private function classFolders(): array
    {
        $folders = array_map('basename', Utility::getDirectoriesOfPath($this->show_id));
        $known = Show::find($this->show_id)?->classes()->pluck('name')->all() ?? [];

        $all = array_values(array_unique(array_filter(
            array_merge($folders, $known),
            fn (string $name) => ! str_starts_with($name, '.') && ! str_starts_with($name, '_') && DirectoryNameValidator::isValid($name),
        )));
        natcasesort($all);

        return array_values($all);
    }
}
