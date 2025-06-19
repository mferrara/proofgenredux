<div class="w-full bg-zinc-800 border border-zinc-800/50 px-3 py-2">
    <div class="max-w-6xl mx-auto flex flex-row justify-end items-center gap-x-4">
    <div class="text-sm flex items-center gap-2">
        <span>Backups:</span>
        @if(config('proofgen.archive_enabled'))
            <flux:badge variant="solid" color="green" size="sm">Enabled</flux:badge>
            @php
                // Ensure the archive path is reachable
                $archive_reachable = true;
                try {
                    $listing = \Illuminate\Support\Facades\Storage::disk('archive')->directories();
                }catch(\Exception $e){
                    $archive_reachable = false;
                }
            @endphp
            @if( ! $archive_reachable)
                <flux:badge variant="solid" color="rose" size="sm">Archive path unreachable</flux:badge>
            @endif
        @else
            <flux:badge variant="outline" color="rose" size="sm">Disabled</flux:badge>
        @endif
    </div>

    <div class="text-sm flex items-center gap-2">
        <span>Uploads:</span>
        @if(config('proofgen.upload_proofs'))
            <flux:badge variant="solid" color="green" size="sm">Enabled</flux:badge>
        @else
            <flux:badge variant="outline" color="amber" size="sm">Disabled</flux:badge>
        @endif
    </div>

    <div class="text-sm flex items-center gap-2">
        <span>Rename:</span>
        @if(config('proofgen.rename_files'))
            <flux:badge variant="solid" color="green" size="sm">Enabled</flux:badge>
        @else
            <flux:badge variant="solid" color="amber" size="sm">Disabled</flux:badge>
        @endif
    </div>

    <div class="text-sm flex items-center gap-2">
        <span>Horizon:</span>
        @if($isHorizonRunning)
            <flux:badge variant="solid" color="green" size="sm">Running</flux:badge>
            @if($autoRestartEnabled)
                <flux:badge variant="outline" color="sky" size="sm">Auto-restart enabled</flux:badge>
            @endif
            <div class="flex items-center gap-1">
                <flux:button
                    wire:click="stopHorizon"
                    wire:loading.attr="disabled"
                    wire:target="stopHorizon"
                    icon="stop"
                    size="xs"
                    variant="ghost"
                    class="text-red-500 hover:text-red-400"
                    title="Stop Horizon"
                />
                <flux:button
                    wire:click="restartHorizon"
                    wire:loading.attr="disabled"
                    wire:target="restartHorizon"
                    icon="arrow-path"
                    size="xs"
                    variant="ghost"
                    class="text-blue-400 hover:text-blue-300"
                    title="Restart Horizon"
                />
            </div>
        @else
            <div class="flex items-center gap-2">
                <flux:badge variant="outline" color="rose" size="sm">Stopped</flux:badge>
                <flux:button
                    wire:click="startHorizon"
                    wire:loading.attr="disabled"
                    wire:target="startHorizon"
                    icon="play"
                    size="xs"
                    variant="ghost"
                    class="text-success hover:text-success/80"
                    title="Start Horizon"
                />
                <flux:tooltip toggleable>
                    <flux:button icon="information-circle" size="xs" variant="ghost" class="text-error!" />

                    <flux:tooltip.content class="max-w-[20rem] space-y-2">
                        <p>Horizon is needed to process tasks.</p>
                        <p>Click the play button to start Horizon</p>
                        <p>Or use the "Start Horizon" button in Settings</p>
                    </flux:tooltip.content>
                </flux:tooltip>
            </div>
        @endif
    </div>
    </div>
</div>
