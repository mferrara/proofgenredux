<?php

namespace App\Livewire;

use App\Models\Photo;
use App\Models\PhotoIssue;
use App\Models\Show;
use App\Models\ShowClass;
use App\Services\FinderRevealService;
use App\Services\ImportConflictHintService;
use App\Services\PhotoArchiveService;
use App\Services\PhotoImportIdentityResolver;
use App\Services\PhotoMoveService;
use App\Services\PhotoService;
use App\Services\SafeFileMover;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class PhotoIssuesComponent extends Component
{
    use WithPagination;

    #[Url(as: 'type', except: 'all')]
    public string $issueType = 'all';

    #[Url(as: 'status', except: 'open')]
    public string $statusFilter = 'open';

    #[Url(as: 'show_id', except: '')]
    public string $showFilter = '';

    #[Url(as: 'show_class_id', except: '')]
    public string $showClassFilter = '';

    public ?int $selectedIssueId = null;

    public string $resolutionNotes = '';

    public ?int $pendingDestructiveIssueId = null;

    public string $pendingDestructiveAction = '';

    public function updatingIssueType(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingShowFilter(): void
    {
        $this->resetPage();
    }

    public function updatingShowClassFilter(): void
    {
        $this->resetPage();
    }

    public function revealInFinder(string $relativePath, string $disk = 'fullsize'): void
    {
        try {
            $root = rtrim((string) config("filesystems.disks.{$disk}.root"), '/');
            if ($root === '') {
                throw new \RuntimeException('Disk root not configured: '.$disk);
            }
            $absolute = $root.'/'.ltrim($relativePath, '/');
            app(FinderRevealService::class)->reveal($absolute);
        } catch (\Throwable $e) {
            Log::warning('Reveal in Finder failed: '.$e->getMessage());
            $this->toast('Reveal failed: '.$e->getMessage(), 'danger');
        }
    }

    public function openIssue(int $issueId): void
    {
        $this->selectedIssueId = $issueId;
        $this->resolutionNotes = '';
    }

    public function closeIssue(): void
    {
        $this->selectedIssueId = null;
        $this->resolutionNotes = '';
    }

    public function confirmDestructive(int $issueId, string $action): void
    {
        $this->pendingDestructiveIssueId = $issueId;
        $this->pendingDestructiveAction = $action;
        Flux::modal('confirm-destructive-issue')->show();
    }

    public function performConfirmedDestructive(): void
    {
        $issueId = $this->pendingDestructiveIssueId;
        $action = $this->pendingDestructiveAction;
        $this->pendingDestructiveIssueId = null;
        $this->pendingDestructiveAction = '';
        Flux::modal('confirm-destructive-issue')->close();

        if ($issueId === null || $action === '') {
            return;
        }

        match ($action) {
            'replace_existing' => $this->replaceExistingWithIncoming($issueId),
            'move_existing' => $this->moveExistingPhotoToThisClass($issueId),
            default => null,
        };
    }

    public function discardIncoming(int $issueId): void
    {
        $issue = PhotoIssue::find($issueId);
        if (! $issue || ! $issue->isOpen()) {
            return;
        }

        try {
            if ($issue->quarantine_path && Storage::disk('fullsize')->exists($issue->quarantine_path)) {
                app(SafeFileMover::class)->bury(
                    disk: 'fullsize',
                    path: $issue->quarantine_path,
                    reason: SafeFileMover::REASON_PHOTO_DELETED,
                    context: [
                        'sha1' => $issue->incoming_sha1,
                        'size' => $issue->incoming_size,
                        'photo_issue_id' => $issue->id,
                        'reason_detail' => 'discarded_from_issues_inbox',
                    ],
                );
            }

            $this->markResolved($issue, 'Discarded incoming source.');
            $this->toast('Incoming source discarded.', 'success');
        } catch (\Throwable $e) {
            Log::error('Failed to discard incoming for issue '.$issueId.': '.$e->getMessage());
            $this->toast('Failed to discard incoming: '.$e->getMessage(), 'danger');

            return;
        }

        $this->closeIssue();
    }

    public function assignNextProofNumberToIncoming(int $issueId): void
    {
        $issue = PhotoIssue::find($issueId);
        if (! $issue || ! $issue->isOpen() || ! $issue->quarantine_path) {
            return;
        }

        // Identical content already has an authoritative record; importing it
        // under a new proof number would create a second row for the same bytes.
        // Refuse before Show::getNextProofNumber() allocates anything.
        if ($issue->issue_type === PhotoIssue::TYPE_DUPLICATE_CONTENT) {
            $this->toast(
                'Duplicate content cannot be imported as a new number. Move the existing photo or discard the incoming source.',
                'danger',
            );

            return;
        }

        try {
            // If the incoming bytes are now owned by any record, refuse before
            // allocating a proof number. The low-level Image guard would reject
            // the write later anyway, but we must not burn a number first.
            $incomingSha1 = $issue->incoming_sha1;
            if ($issue->quarantine_path && Storage::disk('fullsize')->exists($issue->quarantine_path)) {
                $incomingSha1 = sha1(Storage::disk('fullsize')->get($issue->quarantine_path));
            }
            if ($incomingSha1) {
                $owner = Photo::query()->where('sha1', $incomingSha1)->first();
                if ($owner) {
                    throw new \RuntimeException(
                        'Incoming content already belongs to photo '.$owner->id.
                        '; resolve it as a duplicate instead of allocating a new number.'
                    );
                }
            }

            $show = Show::find($issue->show_id);
            if (! $show) {
                throw new \RuntimeException('Show not found: '.$issue->show_id);
            }

            $allocated = $show->getNextProofNumber();
            $this->reimportQuarantinedSource($issue, $allocated);

            $this->markResolved($issue, 'Imported under new proof number '.$allocated.'.');
            $this->toast('Imported as '.$allocated.'.', 'success');
        } catch (\Throwable $e) {
            Log::error('Failed to assign next proof number for issue '.$issueId.': '.$e->getMessage());
            $this->toast('Failed: '.$e->getMessage(), 'danger');

            return;
        }

        $this->closeIssue();
    }

    /**
     * The operator has already decided this conflict resolves to "import this file
     * under THIS proof number." `bypassResolver: true` skips the classification step
     * that produced the issue. `dispatchJobs: true` queues derivative regen so
     * thumbnails/web/highres appear immediately rather than waiting for the next
     * class-view reconciliation pass.
     *
     * NOTE: this runs synchronously inside the Livewire request — archive write +
     * original write + DB row + bury source + 3 job dispatches. For the typical
     * 20-30MB JPG that's well under a second; for very large medium-format files
     * (100MB+) on slow archive disks it could approach the request timeout. If that
     * ever bites, move this to a queued ResolveImportConflict job and have the UI
     * poll for completion.
     */
    private function reimportQuarantinedSource(PhotoIssue $issue, string $proofNumber): Photo
    {
        $result = app(PhotoService::class)->processPhoto(
            imagePath: $issue->quarantine_path,
            proofNumberOverride: $proofNumber,
            debug: false,
            dispatchJobs: true,
            bypassResolver: true,
        );

        return $result['photo'];
    }

    private function replaceExistingWithIncoming(int $issueId): void
    {
        $issue = PhotoIssue::find($issueId);
        if (! $issue || ! $issue->isOpen() || ! $issue->quarantine_path || ! $issue->existing_photo_id) {
            return;
        }

        try {
            $existing = $issue->existingPhoto;
            if (! $existing) {
                throw new \RuntimeException('Existing photo not found: '.$issue->existing_photo_id);
            }

            // Verify the quarantined bytes still match the recorded incoming identity,
            // then confirm no *other* record already owns that content. Deleting the
            // existing row when another photo owns the incoming content would lose
            // data and the DB unique index would reject the re-import anyway.
            if (! Storage::disk('fullsize')->exists($issue->quarantine_path)) {
                throw new \RuntimeException('Quarantined source is missing; refusing to replace the existing photo.');
            }

            $incomingBytes = Storage::disk('fullsize')->get($issue->quarantine_path);
            $incomingSha1 = sha1($incomingBytes);
            if ($issue->incoming_sha1 && $incomingSha1 !== $issue->incoming_sha1) {
                throw new \RuntimeException('Quarantined source no longer matches the recorded incoming SHA; refusing to replace.');
            }

            $otherOwner = Photo::query()
                ->where('sha1', $incomingSha1)
                ->where('id', '!=', $existing->id)
                ->first();
            if ($otherOwner) {
                throw new \RuntimeException(
                    'Incoming content is already owned by photo '.$otherOwner->id.
                    '; resolve as a duplicate instead of replacing the existing photo.'
                );
            }

            // Resolve the identities before touching anything. The composite
            // show_class_id is not safe to split on underscores (both the show id and
            // the class name may contain them), so use the actual ShowClass rows. If
            // either is missing we cannot know where the replacement belongs; refuse
            // before any destructive step and leave the existing photo, its files and
            // the quarantined bytes untouched.
            $existingClass = ShowClass::find($existing->show_class_id);
            if ($existingClass === null) {
                throw new \RuntimeException(
                    'Existing photo class not found: '.$existing->show_class_id.'; refusing to replace.'
                );
            }
            $quarantineClass = ShowClass::find($issue->show_class_id);
            if ($quarantineClass === null) {
                throw new \RuntimeException(
                    'Issue class not found: '.$issue->show_class_id.'; refusing to replace.'
                );
            }

            $prefix = $quarantineClass->show_id.'/'.$quarantineClass->name.'/_import_conflicts/';
            if (! str_starts_with($issue->quarantine_path, $prefix)) {
                throw new \RuntimeException('Quarantined source does not match the issue class; refusing to replace.');
            }

            $existingProofNumber = $existing->proof_number;
            // Reuse the resolved relation so path accessors cannot re-resolve differently.
            $existing->setRelation('showClass', $existingClass);

            // Bury the existing photo's original (and archive if present), then drop the DB row
            // so the next import can claim that proof number unencumbered.
            if ($existing->full_path && file_exists($existing->full_path)) {
                app(SafeFileMover::class)->buryAbsolute(
                    $existing->full_path,
                    SafeFileMover::REASON_DISPLACED_ORIGINAL,
                    [
                        'sha1' => $existing->sha1,
                        'photo_id' => $existing->id,
                        'replaced_by_issue_id' => $issue->id,
                    ],
                );
            }
            if ($existing->archive_path && Storage::disk('archive')->exists($existing->archive_path)) {
                app(SafeFileMover::class)->bury(
                    disk: 'archive',
                    path: $existing->archive_path,
                    reason: SafeFileMover::REASON_DISPLACED_ORIGINAL,
                    context: [
                        'sha1' => $existing->archive_sha1,
                        'size' => $existing->archive_size,
                        'photo_id' => $existing->id,
                        'replaced_by_issue_id' => $issue->id,
                    ],
                );
            }

            $existing->delete();

            // Quarantine path layout is {show}/{class}/_import_conflicts/{file}. The Image
            // constructor reads show/class from segments [0]/[1], which is correct. If the
            // existing photo lived in a different class we relocate the quarantined bytes
            // there first so the imported original lands in the right class.
            $sourcePath = $this->relocateQuarantineForReplace($issue->quarantine_path, $quarantineClass, $existingClass);
            $issue->quarantine_path = $sourcePath;

            $this->reimportQuarantinedSource($issue, $existingProofNumber);

            $this->markResolved($issue, 'Replaced existing photo with incoming under proof number '.$existingProofNumber.'.');
            $this->toast('Replaced existing with incoming.', 'success');
        } catch (\Throwable $e) {
            Log::error('Failed to replace existing for issue '.$issueId.': '.$e->getMessage());
            $this->toast('Failed: '.$e->getMessage(), 'danger');

            return;
        }

        $this->closeIssue();
    }

    /**
     * Move a quarantined source into the class that owns the existing photo so the
     * replacement is imported back into the same class. Uses the resolved ShowClass
     * rows rather than splitting the composite id at an underscore.
     */
    private function relocateQuarantineForReplace(string $quarantinePath, ShowClass $quarantineClass, ShowClass $targetClass): string
    {
        if ($quarantineClass->id === $targetClass->id) {
            return $quarantinePath;
        }

        $targetDirectory = $targetClass->show_id.'/'.$targetClass->name;
        $basename = basename($quarantinePath);
        $target = $targetDirectory.'/'.$basename;
        Storage::disk('fullsize')->makeDirectory($targetDirectory);
        if (! Storage::disk('fullsize')->move($quarantinePath, $target)) {
            throw new \RuntimeException('Failed to relocate quarantined source to '.$target.'; refusing to continue.');
        }

        return $target;
    }

    private function moveExistingPhotoToThisClass(int $issueId): void
    {
        $issue = PhotoIssue::find($issueId);
        if (! $issue || ! $issue->isOpen() || ! $issue->existing_photo_id) {
            return;
        }

        try {
            $existing = $issue->existingPhoto;
            if (! $existing) {
                throw new \RuntimeException('Existing photo not found: '.$issue->existing_photo_id);
            }
            if ($existing->show_class_id === $issue->show_class_id) {
                throw new \RuntimeException('Existing photo is already in this class.');
            }

            $moveResult = app(PhotoMoveService::class)->movePhotos([$existing->id], $issue->show_class_id);
            if (! empty($moveResult['errors'])) {
                throw new \RuntimeException('Move failed: '.implode('; ', $moveResult['errors']));
            }

            // Now bury the (still-quarantined) duplicate source.
            if ($issue->quarantine_path && Storage::disk('fullsize')->exists($issue->quarantine_path)) {
                app(SafeFileMover::class)->bury(
                    disk: 'fullsize',
                    path: $issue->quarantine_path,
                    reason: SafeFileMover::REASON_PHOTO_DELETED,
                    context: [
                        'sha1' => $issue->incoming_sha1,
                        'size' => $issue->incoming_size,
                        'photo_issue_id' => $issue->id,
                        'reason_detail' => 'duplicate_after_existing_moved',
                    ],
                );
            }

            $this->markResolved($issue, 'Moved existing photo into this class; discarded duplicate source.');
            $this->toast('Existing photo moved; duplicate discarded.', 'success');
        } catch (\Throwable $e) {
            Log::error('Failed to move existing photo for issue '.$issueId.': '.$e->getMessage());
            $this->toast('Failed: '.$e->getMessage(), 'danger');

            return;
        }

        $this->closeIssue();
    }

    public function runSafeRepair(int $issueId): void
    {
        $issue = PhotoIssue::find($issueId);
        if (! $issue || ! $issue->isOpen() || ! $issue->existingPhoto) {
            return;
        }

        try {
            $result = app(PhotoArchiveService::class)->repairPhoto($issue->existingPhoto);
            $note = 'Safe repair: '.($result['repair_note'] ?? 'no-op').'.';
            if (($result['status'] ?? '') === 'ok') {
                $this->markResolved($issue, $note);
                $this->toast('Repair completed.', 'success');
            } else {
                $issue->notes = trim(($issue->notes ? $issue->notes."\n" : '').$note);
                $issue->save();
                $this->toast('Repair attempted; issue still requires attention: '.$result['status'], 'warning');
            }
        } catch (\Throwable $e) {
            Log::error('Safe repair failed for issue '.$issueId.': '.$e->getMessage());
            $this->toast('Repair failed: '.$e->getMessage(), 'danger');

            return;
        }

        $this->closeIssue();
    }

    public function markIgnored(int $issueId): void
    {
        $issue = PhotoIssue::find($issueId);
        if (! $issue || ! $issue->isOpen()) {
            return;
        }

        $note = trim($this->resolutionNotes);
        $issue->forceFill([
            'status' => PhotoIssue::STATUS_IGNORED,
            'resolved_at' => now(),
            'resolved_by_user' => Auth::user()?->name ?? 'operator',
            'notes' => $this->appendNote($issue->notes, $note ?: 'Marked ignored.'),
        ])->save();

        $this->toast('Issue marked ignored.', 'success');
        $this->closeIssue();
    }

    private function markResolved(PhotoIssue $issue, string $autoNote): void
    {
        $userNote = trim($this->resolutionNotes);
        $combined = $autoNote;
        if ($userNote !== '') {
            $combined = $autoNote."\n".$userNote;
        }

        $issue->forceFill([
            'status' => PhotoIssue::STATUS_RESOLVED,
            'resolved_at' => now(),
            'resolved_by_user' => Auth::user()?->name ?? 'operator',
            'notes' => $this->appendNote($issue->notes, $combined),
        ])->save();
    }

    private function appendNote(?string $existing, string $addition): string
    {
        $existing = trim((string) $existing);
        if ($existing === '') {
            return $addition;
        }

        return $existing."\n".$addition;
    }

    private function toast(string $text, string $variant): void
    {
        Flux::toast(
            text: $text,
            heading: ucfirst($variant),
            variant: $variant,
            position: 'top right',
        );
    }

    public function render()
    {
        $query = PhotoIssue::query()->with('existingPhoto')->orderByDesc('created_at');

        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }
        if ($this->issueType !== 'all') {
            $query->where('issue_type', $this->issueType);
        }
        if ($this->showFilter !== '') {
            $query->where('show_id', $this->showFilter);
        }
        if ($this->showClassFilter !== '') {
            $query->where('show_class_id', $this->showClassFilter);
        }

        $issues = $query->paginate(25);

        $selectedIssue = null;
        $hints = null;
        $shaMatchesQuarantine = null;
        if ($this->selectedIssueId) {
            $selectedIssue = PhotoIssue::with('existingPhoto', 'showClass')->find($this->selectedIssueId);
            if ($selectedIssue && $selectedIssue->issue_type === PhotoIssue::TYPE_DUPLICATE_CONTENT && $selectedIssue->quarantine_path) {
                $identity = $this->showAndClassFromShowClassId($selectedIssue->show_class_id);
                if ($identity === null) {
                    // Unresolved class: do not guess at an underscore boundary. Leave the
                    // hint panel empty and surface the unresolved identity in the log.
                    Log::warning('Could not build hints for issue '.$selectedIssue->id.': show class not found ('.$selectedIssue->show_class_id.').');
                } else {
                    [$show, $class] = $identity;
                    try {
                        $plan = app(PhotoImportIdentityResolver::class)->resolve(
                            $selectedIssue->quarantine_path,
                            $show,
                            $class,
                        );
                        $shaMatchesQuarantine = $plan->sha1 === $selectedIssue->incoming_sha1;
                        if ($shaMatchesQuarantine) {
                            $hints = app(ImportConflictHintService::class)->hintsFor($plan);
                        }
                    } catch (\Throwable $e) {
                        Log::warning('Could not build hints for issue '.$selectedIssue->id.': '.$e->getMessage());
                    }
                }
            }
        }

        return view('livewire.photo-issues-component', [
            'issues' => $issues,
            'selectedIssue' => $selectedIssue,
            'hints' => $hints,
            'shaMatchesQuarantine' => $shaMatchesQuarantine,
            'issueTypes' => $this->availableIssueTypes(),
            'shows' => Show::query()->orderBy('id')->pluck('id')->all(),
        ])->title('Photo Issues');
    }

    /**
     * Resolve show id / class name from a composite show_class_id through the
     * actual ShowClass relation. The id is not a safe string to split: both the
     * show id and the class name may themselves contain underscores. Returns
     * null when the class cannot be resolved so callers can report it as
     * unresolved instead of inventing an identity.
     *
     * @return array{0: string, 1: string}|null
     */
    private function showAndClassFromShowClassId(?string $showClassId): ?array
    {
        if ($showClassId === null || $showClassId === '') {
            return null;
        }

        $showClass = ShowClass::find($showClassId);
        if ($showClass === null) {
            return null;
        }

        return [$showClass->show_id, $showClass->name];
    }

    private function availableIssueTypes(): array
    {
        return [
            'all' => 'All types',
            PhotoIssue::TYPE_DUPLICATE_CONTENT => 'Duplicate content',
            PhotoIssue::TYPE_PROOF_COLLISION => 'Proof collision',
            PhotoIssue::TYPE_INVALID_NUMBERED_FILENAME => 'Invalid numbered filename',
            PhotoIssue::TYPE_NEEDS_REVIEW => 'Needs review',
            PhotoIssue::TYPE_MISSING_ARCHIVE => 'Missing archive',
            PhotoIssue::TYPE_METADATA_MISMATCH => 'Metadata mismatch',
            PhotoIssue::TYPE_ARCHIVE_CONFLICT => 'Archive conflict',
            PhotoIssue::TYPE_ORPHAN_ORIGINAL => 'Orphan original',
            PhotoIssue::TYPE_MISSING_ORIGINAL => 'Missing original',
        ];
    }
}
