<div class="px-6 lg:px-10 py-6 max-w-7xl mx-auto">
    <div class="mb-6 flex items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1" class="!text-3xl !font-semibold tracking-tight">Photo Issues</flux:heading>
            <flux:text class="mt-1">
                Conflicts surfaced at import or by the archive audit. Resolve, ignore, or repair from here.
            </flux:text>
        </div>
        <div class="flex items-center gap-2">
            <flux:badge color="zinc" size="sm">{{ $issues->total() }} {{ str('issue')->plural($issues->total()) }}</flux:badge>
        </div>
    </div>

    {{-- Filters --}}
    <flux:card class="mb-6">
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-48">
                <flux:select wire:model.live="statusFilter" label="Status" size="sm">
                    <flux:select.option value="open">Open</flux:select.option>
                    <flux:select.option value="resolved">Resolved</flux:select.option>
                    <flux:select.option value="ignored">Ignored</flux:select.option>
                    <flux:select.option value="all">All</flux:select.option>
                </flux:select>
            </div>
            <div class="min-w-56">
                <flux:select wire:model.live="issueType" label="Issue type" size="sm">
                    @foreach($issueTypes as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div class="min-w-40">
                <flux:select wire:model.live="showFilter" label="Show" size="sm">
                    <flux:select.option value="">All shows</flux:select.option>
                    @foreach($shows as $showId)
                        <flux:select.option value="{{ $showId }}">{{ $showId }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div class="min-w-48">
                <flux:input
                    wire:model.live.debounce.300ms="showClassFilter"
                    label="Class id"
                    placeholder="e.g. 22Buck_007"
                    size="sm"
                />
            </div>
        </div>
    </flux:card>

    {{-- Issue list --}}
    <flux:card class="!p-0 overflow-hidden">
        @if($issues->isEmpty())
            <div class="text-center py-14">
                <flux:icon name="check-circle" class="size-10 mx-auto text-emerald-400 dark:text-emerald-500 mb-2" />
                <flux:heading size="base" class="!font-medium">No issues</flux:heading>
                <flux:text class="mt-1">Nothing matches the current filters.</flux:text>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400 border-b border-zinc-200 dark:border-white/10">
                            <th class="py-2 px-4 font-medium">Type</th>
                            <th class="py-2 px-4 font-medium">Show / Class</th>
                            <th class="py-2 px-4 font-medium">Source</th>
                            <th class="py-2 px-4 font-medium">Existing</th>
                            <th class="py-2 px-4 font-medium">SHA</th>
                            <th class="py-2 px-4 font-medium">Created</th>
                            <th class="py-2 px-4 font-medium text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($issues as $issue)
                            <tr class="border-b border-zinc-100 dark:border-white/5 hover:bg-zinc-50/50 dark:hover:bg-white/[0.02]">
                                <td class="py-2 px-4">
                                    <flux:badge color="{{ $issue->severityColor() }}" size="sm">
                                        {{ str_replace('_', ' ', $issue->issue_type) }}
                                    </flux:badge>
                                    @if($issue->status !== 'open')
                                        <flux:badge color="zinc" size="sm" class="ml-1">{{ $issue->status }}</flux:badge>
                                    @endif
                                </td>
                                <td class="py-2 px-4 font-mono text-xs">
                                    {{ $issue->show_id ?? '—' }}<br>
                                    <span class="text-zinc-500 dark:text-zinc-400">{{ $issue->show_class_id ?? '—' }}</span>
                                </td>
                                <td class="py-2 px-4 font-mono text-xs">
                                    @if($issue->source_path)
                                        <div class="truncate max-w-xs" title="{{ $issue->source_path }}">{{ basename($issue->source_path) }}</div>
                                    @endif
                                    @if($issue->quarantine_path)
                                        <div class="truncate max-w-xs text-amber-600 dark:text-amber-400" title="{{ $issue->quarantine_path }}">
                                            <flux:icon name="archive-box" class="inline size-3" />
                                            {{ basename($issue->quarantine_path) }}
                                        </div>
                                    @endif
                                </td>
                                <td class="py-2 px-4 font-mono text-xs">
                                    @if($issue->existing_photo_id)
                                        <div title="{{ $issue->existing_photo_id }}">{{ $issue->existing_proof_number ?? '—' }}</div>
                                        <div class="text-zinc-500 truncate max-w-[10rem]">{{ $issue->existing_photo_id }}</div>
                                    @else
                                        <span class="text-zinc-400">—</span>
                                    @endif
                                </td>
                                <td class="py-2 px-4 font-mono text-xs">
                                    @if($issue->incoming_sha1)
                                        <span title="incoming: {{ $issue->incoming_sha1 }}">in: {{ substr($issue->incoming_sha1, 0, 8) }}</span>
                                    @endif
                                    @if($issue->existing_sha1)
                                        <br><span title="existing: {{ $issue->existing_sha1 }}" class="text-zinc-500">ex: {{ substr($issue->existing_sha1, 0, 8) }}</span>
                                    @endif
                                </td>
                                <td class="py-2 px-4 whitespace-nowrap text-xs text-zinc-500">
                                    <span title="{{ $issue->created_at?->toDayDateTimeString() }}">{{ $issue->created_at?->diffForHumans() }}</span>
                                </td>
                                <td class="py-2 px-4 text-right">
                                    <div class="inline-flex items-center gap-1">
                                        @if($issue->quarantine_path)
                                            <flux:button
                                                size="xs"
                                                variant="ghost"
                                                icon="folder-open"
                                                title="Reveal quarantined source in Finder"
                                                wire:click="revealInFinder(@js($issue->quarantine_path), 'fullsize')"
                                            />
                                        @endif
                                        @if($issue->isOpen())
                                            <flux:button size="xs" variant="primary" wire:click="openIssue({{ $issue->id }})">
                                                View / Resolve
                                            </flux:button>
                                        @else
                                            <flux:button size="xs" variant="ghost" wire:click="openIssue({{ $issue->id }})">
                                                View
                                            </flux:button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="px-4 py-3 border-t border-zinc-100 dark:border-white/5">
                {{ $issues->links() }}
            </div>
        @endif
    </flux:card>

    {{-- Resolution modal --}}
    @if($selectedIssue)
        <flux:modal wire:model.self="selectedIssueId" name="resolve-issue" class="max-w-3xl"
                    x-on:close="$wire.closeIssue()">
            <div class="space-y-5">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <flux:heading size="lg">
                            <flux:badge color="{{ $selectedIssue->severityColor() }}" size="sm">
                                {{ str_replace('_', ' ', $selectedIssue->issue_type) }}
                            </flux:badge>
                            <span class="ml-2">Issue #{{ $selectedIssue->id }}</span>
                        </flux:heading>
                        <flux:text class="mt-1 text-xs">
                            {{ $selectedIssue->show_class_id ?? '—' }} · created {{ $selectedIssue->created_at?->diffForHumans() }}
                        </flux:text>
                    </div>
                    <flux:badge color="{{ $selectedIssue->isOpen() ? 'amber' : 'zinc' }}" size="sm">
                        {{ $selectedIssue->status }}
                    </flux:badge>
                </div>

                {{-- Conflict locations key --}}
                <div class="rounded-md border border-zinc-200 dark:border-white/10 p-3 text-xs space-y-1.5">
                    @if($selectedIssue->source_path)
                        <div>
                            <span class="text-zinc-500">Original ingest path:</span>
                            <code class="font-mono ml-1">{{ $selectedIssue->source_path }}</code>
                        </div>
                    @endif
                    @if($selectedIssue->quarantine_path)
                        <div class="flex items-start gap-2">
                            <div class="grow">
                                <span class="text-zinc-500">Quarantined source (in <code>_import_conflicts/</code>):</span>
                                <code class="font-mono ml-1 text-amber-600 dark:text-amber-400 break-all">{{ $selectedIssue->quarantine_path }}</code>
                            </div>
                            <flux:button
                                size="xs"
                                variant="ghost"
                                icon="folder-open"
                                title="Reveal in Finder"
                                wire:click="revealInFinder(@js($selectedIssue->quarantine_path), 'fullsize')"
                            />
                        </div>
                    @endif
                    @if($selectedIssue->issue_type === \App\Models\PhotoIssue::TYPE_ARCHIVE_CONFLICT && ($selectedIssue->evidence['archive_path'] ?? null))
                        <div class="flex items-start gap-2">
                            <div class="grow">
                                <span class="text-zinc-500">Existing archive copy (under <code>_conflicts/</code> on archive disk):</span>
                                <code class="font-mono ml-1">{{ $selectedIssue->evidence['archive_path'] }}</code>
                            </div>
                            <flux:button
                                size="xs"
                                variant="ghost"
                                icon="folder-open"
                                title="Reveal in Finder"
                                wire:click="revealInFinder(@js($selectedIssue->evidence['archive_path']), 'archive')"
                            />
                        </div>
                    @endif
                    <div class="text-zinc-500 italic pt-1 border-t border-zinc-100 dark:border-white/5">
                        Buried files (post-resolution) live under <code>_graveyard/</code> and are managed from the Graveyard page.
                    </div>
                </div>

                {{-- Identity details --}}
                <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-xs">
                    @if($selectedIssue->incoming_sha1)
                        <div>
                            <dt class="text-zinc-500">Incoming SHA1</dt>
                            <dd class="font-mono">{{ $selectedIssue->incoming_sha1 }}</dd>
                        </div>
                    @endif
                    @if($selectedIssue->existing_sha1)
                        <div>
                            <dt class="text-zinc-500">Existing SHA1</dt>
                            <dd class="font-mono">{{ $selectedIssue->existing_sha1 }}</dd>
                        </div>
                    @endif
                    @if($selectedIssue->intended_proof_number)
                        <div>
                            <dt class="text-zinc-500">Intended proof number</dt>
                            <dd class="font-mono">{{ $selectedIssue->intended_proof_number }}</dd>
                        </div>
                    @endif
                    @if($selectedIssue->existing_proof_number)
                        <div>
                            <dt class="text-zinc-500">Existing proof number</dt>
                            <dd class="font-mono">{{ $selectedIssue->existing_proof_number }}</dd>
                        </div>
                    @endif
                    @if($selectedIssue->existing_photo_id)
                        <div class="col-span-2">
                            <dt class="text-zinc-500">Existing photo</dt>
                            <dd class="font-mono">{{ $selectedIssue->existing_photo_id }}</dd>
                        </div>
                    @endif
                </dl>

                {{-- Hints --}}
                @if($hints !== null)
                    <div class="rounded-md border border-sky-200 dark:border-sky-800/50 bg-sky-50/50 dark:bg-sky-900/20 p-3 text-xs">
                        <div class="font-medium mb-2">Placement hints</div>
                        @if($shaMatchesQuarantine === false)
                            <div class="text-rose-600 dark:text-rose-400">
                                Quarantined SHA does not match the recorded incoming SHA — hints suppressed.
                            </div>
                        @else
                            <div class="mb-2">
                                <span class="text-zinc-500">Incoming filename:</span>
                                <code class="font-mono">{{ $hints['incoming']['original_filename'] ?? '—' }}</code>
                                @if($hints['incoming']['ordinal'] ?? null)
                                    · ordinal <code class="font-mono">{{ $hints['incoming']['ordinal'] }}</code>
                                @endif
                                @if($hints['incoming']['capture_time'] ?? null)
                                    · captured <code class="font-mono">{{ $hints['incoming']['capture_time'] }}</code>
                                @endif
                            </div>
                            <div class="space-y-2">
                                @foreach($hints['candidates'] as $classId => $candidate)
                                    <div class="rounded border border-zinc-200 dark:border-white/10 p-2">
                                        <div class="font-medium">{{ $classId }} ({{ $candidate['photo_count'] }} photos)</div>
                                        @if($candidate['ordinal_fit'])
                                            <div class="text-zinc-600 dark:text-zinc-400">
                                                Ordinal {{ $candidate['ordinal_fit']['within_range'] ? 'within' : 'outside' }} range
                                                [{{ $candidate['ordinal_fit']['min'] }}, {{ $candidate['ordinal_fit']['max'] }}]
                                            </div>
                                        @endif
                                        @if($candidate['time_fit'])
                                            <div class="text-zinc-600 dark:text-zinc-400">
                                                Time {{ $candidate['time_fit']['within_range'] ? 'within' : 'outside' }} sibling capture range
                                            </div>
                                        @endif
                                        @foreach($candidate['notes'] as $note)
                                            <div class="text-zinc-500 italic">{{ $note }}</div>
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif

                @if($selectedIssue->notes)
                    <div class="text-xs">
                        <div class="text-zinc-500 mb-1">Notes</div>
                        <pre class="font-mono whitespace-pre-wrap rounded bg-zinc-50 dark:bg-white/[0.04] p-2">{{ $selectedIssue->notes }}</pre>
                    </div>
                @endif

                @if($selectedIssue->isOpen())
                    <flux:textarea
                        wire:model="resolutionNotes"
                        label="Notes (optional)"
                        placeholder="Why this resolution? Anything noteworthy?"
                        rows="2"
                    />

                    <div class="flex flex-wrap items-center gap-2 pt-2 border-t border-zinc-200 dark:border-white/10">
                        @if($selectedIssue->hasQuarantinedSource())
                            <flux:button size="sm" variant="primary" icon="arrow-down-tray"
                                         wire:click="assignNextProofNumberToIncoming({{ $selectedIssue->id }})">
                                Assign next proof number to incoming
                            </flux:button>
                            <flux:button size="sm" variant="ghost" icon="trash"
                                         wire:click="discardIncoming({{ $selectedIssue->id }})">
                                Discard incoming
                            </flux:button>
                            @if($selectedIssue->existing_photo_id)
                                <flux:button size="sm" variant="danger" icon="exclamation-triangle"
                                             wire:click="confirmDestructive({{ $selectedIssue->id }}, 'replace_existing')">
                                    Replace existing with incoming
                                </flux:button>
                            @endif
                            @if($selectedIssue->issue_type === \App\Models\PhotoIssue::TYPE_DUPLICATE_CONTENT
                                && $selectedIssue->existingPhoto
                                && $selectedIssue->existingPhoto->show_class_id !== $selectedIssue->show_class_id)
                                <flux:button size="sm" variant="filled" icon="arrows-right-left"
                                             wire:click="confirmDestructive({{ $selectedIssue->id }}, 'move_existing')">
                                    Move existing photo to this class
                                </flux:button>
                            @endif
                        @elseif(in_array($selectedIssue->issue_type, [
                            \App\Models\PhotoIssue::TYPE_MISSING_ARCHIVE,
                            \App\Models\PhotoIssue::TYPE_METADATA_MISMATCH,
                        ]))
                            <flux:button size="sm" variant="primary" icon="wrench-screwdriver"
                                         wire:click="runSafeRepair({{ $selectedIssue->id }})">
                                Run safe repair
                            </flux:button>
                        @endif

                        <flux:button size="sm" variant="ghost" wire:click="markIgnored({{ $selectedIssue->id }})">
                            Mark ignored
                        </flux:button>

                        <div class="grow"></div>

                        <flux:modal.close>
                            <flux:button size="sm" variant="ghost" wire:click="closeIssue">Close</flux:button>
                        </flux:modal.close>
                    </div>
                @else
                    <div class="flex justify-end pt-2 border-t border-zinc-200 dark:border-white/10">
                        <flux:modal.close>
                            <flux:button size="sm" variant="ghost" wire:click="closeIssue">Close</flux:button>
                        </flux:modal.close>
                    </div>
                @endif
            </div>
        </flux:modal>
    @endif

    {{-- Destructive confirmation --}}
    <flux:modal name="confirm-destructive-issue" class="max-w-md">
        <div class="space-y-5">
            <flux:heading size="lg">Confirm destructive action</flux:heading>
            @if($pendingDestructiveAction === 'replace_existing')
                <flux:text>
                    This will bury the existing photo's original (and archive copy if present), delete its DB row,
                    and re-import the quarantined source under that proof number. Buried files are recoverable from
                    the Graveyard.
                </flux:text>
            @elseif($pendingDestructiveAction === 'move_existing')
                <flux:text>
                    This will move the existing photo from its current class into this issue's class, then bury the
                    quarantined duplicate. The duplicate is recoverable from the Graveyard.
                </flux:text>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="performConfirmedDestructive">
                    Proceed
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
