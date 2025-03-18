<div class="w-full bg-gray-700 border border-gray-600 px-2 py-1 flex flex-row justify-end items-center gap-x-2">
    <div class="text-sm">
        Backups:
        @if(config('proofgen.archive_enabled'))
            <span class="text-success px-1 py-0.5 font-semibold">Enabled</span>
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
                <span class="text-error px-1 py-0.5">Archive path unreachable</span>
            @endif
        @else
            <span class="text-error px-1 py-0.5">Disabled</span>
        @endif
    </div>
    <div class="text-sm">
        Uploads:
        @if(config('proofgen.upload_proofs'))
            <span class="text-success px-1 py-0.5">Enabled</span>
        @else
            <span class="text-yellow-700 px-1 py-0.5">Disabled</span>
        @endif
    </div>
    <div class="text-sm">
        Rename:
        @if(config('proofgen.rename_files'))
            <span class="text-success px-1 py-0.5">Enabled</span>
        @else
            <span class="text-yellow-700 px-1 py-0.5">Disabled</span>
        @endif
    </div>
    <div class="text-sm flex flex-row items-center">
        <div>Horizon: &nbsp;</div>
        @if($isHorizonRunning)
            <span class="text-success px-1 py-0.5">Running</span>
            @if($autoRestartEnabled)
                <span class="text-xs text-blue-400 ml-1">(Auto-restart enabled)</span>
            @endif
        @else
            <div class="flex items-center gap-2">
                <span class="text-error px-1 py-0.5">Stopped</span>
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
