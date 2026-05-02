@php
    $archive_reachable = true;
    if (config('proofgen.archive_enabled')) {
        try {
            \Illuminate\Support\Facades\Storage::disk('archive')->directories();
        } catch (\Exception $e) {
            $archive_reachable = false;
        }
    }
@endphp

<div class="w-full border-b border-zinc-200 dark:border-white/10 bg-zinc-50 dark:bg-zinc-900/40">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-2 flex items-center justify-end gap-x-4 text-sm">
        {{-- Backups --}}
        <div class="flex items-center gap-1.5">
            <span class="text-zinc-500 dark:text-zinc-400">Backups</span>
            @if(config('proofgen.archive_enabled'))
                @if($archive_reachable)
                    <flux:badge color="emerald" size="sm">On</flux:badge>
                @else
                    <flux:badge color="rose" size="sm" icon="exclamation-triangle">Path unreachable</flux:badge>
                @endif
            @else
                <flux:badge color="zinc" size="sm">Off</flux:badge>
            @endif
        </div>

        <div class="h-4 w-px bg-zinc-200 dark:bg-white/10"></div>

        {{-- Uploads --}}
        <div class="flex items-center gap-1.5">
            <span class="text-zinc-500 dark:text-zinc-400">Uploads</span>
            @if(config('proofgen.upload_proofs'))
                <flux:badge color="emerald" size="sm">On</flux:badge>
            @else
                <flux:badge color="zinc" size="sm">Off</flux:badge>
            @endif
        </div>

        <div class="h-4 w-px bg-zinc-200 dark:bg-white/10"></div>

        {{-- Rename --}}
        <div class="flex items-center gap-1.5">
            <span class="text-zinc-500 dark:text-zinc-400">Rename</span>
            @if(config('proofgen.rename_files'))
                <flux:badge color="emerald" size="sm">On</flux:badge>
            @else
                <flux:badge color="zinc" size="sm">Off</flux:badge>
            @endif
        </div>

        <div class="h-4 w-px bg-zinc-200 dark:bg-white/10"></div>

        {{-- Horizon --}}
        <div class="flex items-center gap-1.5">
            <span class="text-zinc-500 dark:text-zinc-400">Horizon</span>
            @if($isHorizonRunning)
                <flux:badge color="emerald" size="sm" icon="bolt">Running</flux:badge>
                @if($autoRestartEnabled)
                    <flux:tooltip content="Auto-restart on settings change is enabled" position="bottom">
                        <flux:icon name="arrow-path" class="size-3.5 text-sky-500 dark:text-sky-400" />
                    </flux:tooltip>
                @endif
                <flux:button
                    wire:click="restartHorizon"
                    wire:loading.attr="disabled"
                    wire:target="restartHorizon"
                    icon="arrow-path"
                    size="xs"
                    variant="ghost"
                    square
                    tooltip="Restart Horizon"
                />
                <flux:button
                    wire:click="stopHorizon"
                    wire:loading.attr="disabled"
                    wire:target="stopHorizon"
                    icon="stop"
                    size="xs"
                    variant="ghost"
                    square
                    tooltip="Stop Horizon"
                />
            @else
                <flux:badge color="rose" size="sm">Stopped</flux:badge>
                <flux:button
                    wire:click="startHorizon"
                    wire:loading.attr="disabled"
                    wire:target="startHorizon"
                    icon="play"
                    size="xs"
                    variant="ghost"
                    square
                    tooltip="Start Horizon"
                />
                <flux:tooltip toggleable position="bottom">
                    <flux:button icon="information-circle" size="xs" variant="ghost" square />
                    <flux:tooltip.content class="max-w-xs space-y-1.5">
                        <p>Horizon processes background tasks (image generation, uploads).</p>
                        <p>Click <strong>play</strong> to start it, or use the Settings → Services panel.</p>
                    </flux:tooltip.content>
                </flux:tooltip>
            @endif
        </div>
    </div>
</div>
