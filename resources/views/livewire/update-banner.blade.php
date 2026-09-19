<div wire:init="check">
    @if($offer)
        <div class="w-full bg-sky-600 text-white dark:bg-sky-700">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-1.5 flex flex-wrap items-center justify-between gap-x-4 gap-y-1 text-sm">
                <div wire:loading.remove wire:target="updateNow" class="flex items-center gap-2">
                    <flux:icon name="arrow-down-tray" class="size-4 shrink-0" />
                    <span>
                        Proofgen <strong class="font-mono">{{ $offer['latest'] }}</strong> is available.
                        <span class="text-sky-100">You have {{ $offer['current'] }}.</span>
                    </span>
                </div>
                <div wire:loading.flex wire:target="updateNow" class="items-center gap-2">
                    <flux:icon.loading class="size-4 shrink-0" />
                    <span>Updating Proofgen. This takes a few minutes; keep this page open.</span>
                </div>

                <div wire:loading.remove wire:target="updateNow" class="flex items-center gap-3">
                    <button type="button" wire:click="skipVersion" class="text-sky-100 hover:text-white underline-offset-2 hover:underline">Skip this version</button>
                    <button type="button" wire:click="remindLater" class="text-sky-100 hover:text-white underline-offset-2 hover:underline">Remind me later</button>
                    <button
                        type="button"
                        wire:click="updateNow"
                        wire:confirm="Update Proofgen now? It pauses for a few minutes. Photos waiting to be processed or uploaded carry on afterwards."
                        class="rounded-md bg-white px-3 py-1 font-medium text-sky-700 hover:bg-sky-50"
                    >
                        Update now
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
