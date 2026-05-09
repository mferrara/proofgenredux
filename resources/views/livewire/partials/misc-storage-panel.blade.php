@php
    /** @var array|null $misc_storage Two entries: sample_images and backups */
    $loadAction ??= 'loadMiscStorage';
    $refreshAction ??= 'refreshMiscStorage';
    $format = fn ($bytes) => \App\Services\StorageUsageService::formatBytes((int) $bytes);
@endphp

<div class="rounded border border-zinc-200 bg-zinc-50 p-3 text-sm dark:border-zinc-700 dark:bg-zinc-800/50">
    <div class="flex items-center justify-between gap-2">
        <div class="font-medium text-zinc-700 dark:text-zinc-200">Other disk usage</div>
        @if ($misc_storage)
            <flux:button size="xs" variant="ghost" icon="arrow-path" wire:click="{{ $refreshAction }}"
                wire:loading.attr="disabled" wire:target="{{ $refreshAction }}">
                Refresh
            </flux:button>
        @else
            <flux:button size="xs" variant="ghost" icon="circle-stack" wire:click="{{ $loadAction }}"
                wire:loading.attr="disabled" wire:target="{{ $loadAction }}">
                Calculate
            </flux:button>
        @endif
    </div>

    @if ($misc_storage)
        <dl class="mt-2 grid grid-cols-1 gap-1 sm:grid-cols-2">
            @foreach (['sample_images' => 'Sample images', 'backups' => 'Backups (in-place updater)'] as $key => $label)
                @php $entry = $misc_storage[$key]; @endphp
                <div class="flex items-baseline justify-between gap-2">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                    <dd class="font-mono text-zinc-700 dark:text-zinc-200">
                        @if ($entry['exists'])
                            {{ $format($entry['bytes']) }}
                            <span class="text-xs text-zinc-400">({{ number_format($entry['count']) }})</span>
                        @else
                            <span class="text-xs italic text-zinc-400">not present</span>
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>
    @else
        <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
            Walks storage/sample_images and the backups directory used by the in-place updater. Cached for 10 minutes.
        </div>
    @endif
</div>
