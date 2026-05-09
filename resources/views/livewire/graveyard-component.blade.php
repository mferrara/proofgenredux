<div class="px-6 lg:px-10 py-6 max-w-6xl mx-auto">
    <div class="mb-6">
        <flux:heading size="xl" level="1" class="!text-3xl !font-semibold tracking-tight">Graveyard</flux:heading>
        <flux:text class="mt-1">
            Source-of-truth files removed from the working tree are buried here with JSON sidecars.
            Files older than <strong>{{ $agedDays }}</strong> days are eligible for purge.
        </flux:text>
    </div>

    {{-- Summary cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-8">
        @foreach($summary as $diskName => $stats)
            <flux:card>
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <flux:heading size="base" class="!font-medium capitalize">{{ $diskName }} disk</flux:heading>
                            @if($stats['aged_file_count'] > 0)
                                <flux:badge color="amber" size="sm">{{ $stats['aged_file_count'] }} aged</flux:badge>
                            @elseif($stats['file_count'] > 0)
                                <flux:badge color="zinc" size="sm">All recent</flux:badge>
                            @else
                                <flux:badge color="emerald" size="sm">Empty</flux:badge>
                            @endif
                        </div>
                        <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1.5 text-sm">
                            <dt class="text-zinc-500 dark:text-zinc-400">Files</dt>
                            <dd class="font-mono text-right">{{ number_format($stats['file_count']) }}</dd>

                            <dt class="text-zinc-500 dark:text-zinc-400">Total size</dt>
                            <dd class="font-mono text-right">{{ $this->formatBytes($stats['total_bytes']) }}</dd>

                            <dt class="text-zinc-500 dark:text-zinc-400">Oldest</dt>
                            <dd class="font-mono text-right">
                                {{ $stats['oldest_buried_at'] ? $stats['oldest_buried_at']->diffForHumans() : '—' }}
                            </dd>
                        </dl>
                    </div>
                    <div class="shrink-0 flex flex-col gap-2">
                        <flux:button
                            size="sm"
                            variant="{{ $disk === $diskName ? 'primary' : 'ghost' }}"
                            wire:click="selectDisk('{{ $diskName }}')"
                        >
                            View
                        </flux:button>
                        <flux:button
                            size="sm"
                            variant="danger"
                            icon="trash"
                            :disabled="$stats['aged_file_count'] === 0"
                            wire:click="confirmPurge('{{ $diskName }}')"
                        >
                            Purge {{ $agedDays }}d+
                        </flux:button>
                    </div>
                </div>
            </flux:card>
        @endforeach
    </div>

    {{-- Entry list --}}
    <flux:card>
        <div class="flex items-center justify-between gap-3 mb-4">
            <flux:heading size="base" class="!font-medium">
                Buried files on <span class="capitalize">{{ $disk }}</span>
            </flux:heading>
            <flux:badge color="zinc" size="sm">Oldest first</flux:badge>
        </div>

        @if(empty($entries))
            <div class="text-center py-10">
                <flux:icon name="archive-box" class="size-10 mx-auto text-zinc-400 dark:text-zinc-500 mb-2" />
                <flux:text>No buried files on this disk.</flux:text>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400 border-b border-zinc-200 dark:border-white/10">
                            <th class="py-2 pr-3 font-medium">Original path</th>
                            <th class="py-2 pr-3 font-medium">Reason</th>
                            <th class="py-2 pr-3 font-medium">Buried</th>
                            <th class="py-2 pr-3 font-medium text-right">Size</th>
                            <th class="py-2 pr-3 font-medium">SHA1</th>
                            <th class="py-2 pr-3 font-medium text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($entries as $entry)
                            <tr class="border-b border-zinc-100 dark:border-white/5">
                                <td class="py-2 pr-3 font-mono text-xs truncate max-w-md" title="{{ $entry['original_path'] ?? $entry['graveyard_path'] }}">
                                    {{ $entry['original_path'] ?? $entry['graveyard_path'] }}
                                </td>
                                <td class="py-2 pr-3">
                                    @if($entry['reason'])
                                        <flux:badge color="zinc" size="sm">{{ $entry['reason'] }}</flux:badge>
                                    @else
                                        <span class="text-zinc-400">—</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-3 whitespace-nowrap">
                                    @if($entry['buried_at'])
                                        <span title="{{ $entry['buried_at']->toDayDateTimeString() }}">{{ $entry['buried_at']->diffForHumans() }}</span>
                                    @else
                                        <span class="text-zinc-400">—</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-3 font-mono text-right whitespace-nowrap">{{ $this->formatBytes($entry['size']) }}</td>
                                <td class="py-2 pr-3 font-mono text-xs">{{ $entry['sha1'] ? substr($entry['sha1'], 0, 10) : '—' }}</td>
                                <td class="py-2 pr-3 text-right">
                                    <flux:button
                                        size="xs"
                                        variant="ghost"
                                        icon="folder-open"
                                        title="Reveal buried file in Finder"
                                        wire:click="revealInFinder(@js($entry['graveyard_path']), @js($disk))"
                                    />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex items-center justify-between mt-4">
                <flux:text class="text-xs text-zinc-500">Page {{ $page }}</flux:text>
                <div class="flex gap-2">
                    <flux:button
                        size="xs"
                        variant="ghost"
                        :disabled="$page <= 1"
                        wire:click="previousPage"
                    >Previous</flux:button>
                    <flux:button
                        size="xs"
                        variant="ghost"
                        :disabled="!$hasMore"
                        wire:click="nextPage"
                    >Next</flux:button>
                </div>
            </div>
        @endif
    </flux:card>

    {{-- Purge confirmation modal --}}
    <flux:modal name="confirm-purge" class="max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Purge graveyard files?</flux:heading>
                <flux:text class="mt-2">
                    This will permanently delete buried files older than
                    <strong>{{ $purgeDays }}</strong> days from the
                    <strong class="capitalize">{{ $pendingPurgeDisk ?? '' }}</strong> disk,
                    along with their JSON sidecars. This cannot be undone.
                </flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" icon="trash" wire:click="purge">
                    Purge files
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
