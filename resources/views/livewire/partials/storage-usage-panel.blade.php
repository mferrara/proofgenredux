@php
    /** @var array|null $storage_usage Per-category usage from StorageUsageService */
    /** @var string $loadAction Livewire method to call when "Calculate" is clicked */
    /** @var string $refreshAction Livewire method to call to recompute */
    $loadAction ??= 'loadStorageUsage';
    $refreshAction ??= 'refreshStorageUsage';
    $format = fn ($bytes) => \App\Services\StorageUsageService::formatBytes((int) $bytes);
@endphp

<div class="rounded border border-zinc-200 bg-zinc-50 p-3 text-sm dark:border-zinc-700 dark:bg-zinc-800/50">
    <div class="flex items-center justify-between gap-2">
        <div class="font-medium text-zinc-700 dark:text-zinc-200">Storage usage</div>
        @if ($storage_usage)
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

    @if ($storage_usage)
        <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 sm:grid-cols-3">
            @foreach (\App\Services\StorageUsageService::CATEGORIES as $category)
                @php
                    $bytes = $storage_usage[$category]['bytes'] ?? 0;
                    $count = $storage_usage[$category]['count'] ?? 0;
                    $label = match ($category) {
                        'originals' => 'Originals',
                        'proofs' => 'Proofs',
                        'web_images' => 'Web',
                        'highres_images' => 'Highres',
                        'archive' => 'Archive',
                        default => ucfirst($category),
                    };
                @endphp
                <div class="flex items-baseline justify-between gap-2">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                    <dd class="font-mono text-zinc-700 dark:text-zinc-200">
                        {{ $format($bytes) }}
                        <span class="text-xs text-zinc-400">({{ number_format($count) }})</span>
                    </dd>
                </div>
            @endforeach

            <div
                class="col-span-2 mt-1 flex items-baseline justify-between gap-2 border-t border-zinc-300 pt-1 sm:col-span-3 dark:border-zinc-600">
                <dt class="font-medium text-zinc-700 dark:text-zinc-200">Total</dt>
                <dd class="font-mono font-semibold text-zinc-900 dark:text-zinc-100">
                    {{ $format($storage_usage['total']['bytes'] ?? 0) }}
                    <span class="text-xs text-zinc-400">({{ number_format($storage_usage['total']['count'] ?? 0) }})</span>
                </dd>
            </div>
        </dl>
    @else
        <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
            Walking the image trees can take a moment for large classes/shows. Cached for 10 minutes.
        </div>
    @endif
</div>
