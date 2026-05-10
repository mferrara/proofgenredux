@php
    $summary ??= [];
    $activeProfile ??= null;
    $total = (int) ($summary['total'] ?? 0);
    $verified = (int) ($summary['verified'] ?? 0);
    $failed = (int) ($summary['failed'] ?? 0);
    $missingSources = (int) ($summary['missing_sources'] ?? 0);
    $strays = (int) ($summary['strays'] ?? 0);
    $percent = $total > 0 ? min(100, (int) floor(($verified / $total) * 100)) : 0;
    $canMigrate = $total > 0 && $verified === $total && $failed === 0 && $missingSources === 0;
@endphp

<flux:card class="!p-0 overflow-hidden">
    <div class="flex flex-wrap items-start justify-between gap-4 border-b border-zinc-200 px-4 py-3 dark:border-white/10">
        <div>
            <div class="text-sm font-medium text-zinc-900 dark:text-white">Cloud migration</div>
            <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                Active target: {{ $activeProfile?->label ?? 'None' }}
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:button size="xs" variant="ghost" icon="arrow-path" wire:click="runMigrationInventory">Inventory</flux:button>
            <flux:button size="xs" variant="ghost" icon="cloud-arrow-up" wire:click="copyMigrationToCloud" :disabled="$total === 0">Copy</flux:button>
            <flux:button size="xs" variant="ghost" icon="check-circle" wire:click="verifyMigrationCopies(false)" :disabled="$total === 0">Verify</flux:button>
            <flux:button size="xs" variant="ghost" icon="finger-print" wire:click="verifyMigrationCopies(true)" :disabled="$total === 0">SHA1</flux:button>
            <flux:button size="xs" variant="primary" icon="arrow-right-circle" wire:click="cutoverMigration" :disabled="! $canMigrate">Migrate</flux:button>
            <flux:button size="xs" variant="ghost" icon="magnifying-glass" wire:click="pollMigrationVerification">Poll</flux:button>
        </div>
    </div>

    <div class="space-y-4 p-4">
        <div>
            <div class="mb-1 flex items-center justify-between text-xs text-zinc-500 dark:text-zinc-400">
                <span>{{ number_format($verified) }} / {{ number_format($total) }} verified</span>
                <span>{{ $percent }}%</span>
            </div>
            <div class="h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                <div class="h-full rounded-full bg-emerald-500" style="width: {{ $percent }}%"></div>
            </div>
        </div>

        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
            @foreach(($summary['by_content_type'] ?? []) as $contentType => $counts)
                <div class="rounded-md border border-zinc-200 p-3 dark:border-white/10">
                    <div class="text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">{{ str($contentType)->replace('_', ' ')->headline() }}</div>
                    <div class="mt-2 flex flex-wrap gap-1">
                        <flux:badge color="zinc" size="sm">{{ $counts['total'] ?? 0 }} total</flux:badge>
                        <flux:badge color="emerald" size="sm">{{ $counts['verified'] ?? 0 }} verified</flux:badge>
                        @if(($counts['copied'] ?? 0) > 0)
                            <flux:badge color="sky" size="sm">{{ $counts['copied'] }} copied</flux:badge>
                        @endif
                        @if(($counts['failed'] ?? 0) > 0)
                            <flux:badge color="rose" size="sm">{{ $counts['failed'] }} failed</flux:badge>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        @if($missingSources > 0 || $strays > 0 || $failed > 0)
            <div class="flex flex-wrap gap-2">
                @if($missingSources > 0)
                    <a href="{{ route('photo-issues', ['show_id' => $show->id]) }}">
                        <flux:badge color="amber" size="sm" icon="exclamation-triangle">{{ $missingSources }} missing sources</flux:badge>
                    </a>
                @endif
                @if($strays > 0)
                    <flux:badge color="sky" size="sm">{{ $strays }} strays</flux:badge>
                @endif
                @if($failed > 0)
                    <flux:badge color="rose" size="sm">{{ $failed }} failed copies</flux:badge>
                @endif
            </div>
        @endif
    </div>
</flux:card>
