@php
    $importedCount = $photos_imported->count();
    $pendingProofs = $photos_pending_proofs->count();
    $pendingWeb = $photos_pending_web_images->count();
    $pendingHighres = $photos_pending_highres_images->count();
    $pendingProofUploads = $photos_pending_proof_uploads->count();
    $pendingWebUploads = $photos_pending_web_image_uploads->count();
    $pendingHighresUploads = $photos_pending_highres_image_uploads->count();
    $pendingImportCount = is_array($photos_pending_import) ? count($photos_pending_import) : 0;
    $totalPendingUploads = $pendingProofUploads + $pendingWebUploads + $pendingHighresUploads;
@endphp

<div class="col-span-12 lg:col-span-7">
    <flux:card class="!p-0 overflow-hidden">
        {{-- Per-stage pending tiles --}}
        <div class="grid grid-cols-2 divide-x divide-y divide-zinc-200 dark:divide-white/10
                    border-b border-zinc-200 dark:border-white/10">
            {{-- Imports --}}
            <div class="p-4">
                <div class="text-xs uppercase tracking-wider font-medium text-zinc-500 dark:text-zinc-400 mb-2">Imports</div>
                <div class="flex items-center justify-between gap-3">
                    <flux:badge :color="$pendingImportCount > 0 ? 'amber' : 'zinc'" size="lg" inset="top bottom">{{ $pendingImportCount }}</flux:badge>
                    @if($pendingImportCount > 0)
                        <flux:button wire:click="importPendingImages" wire:loading.attr="disabled" wire:target="importPendingImages" size="xs" variant="primary">Import</flux:button>
                    @else
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">Nothing pending</span>
                    @endif
                </div>
            </div>

            {{-- Proofs --}}
            <div class="p-4">
                <div class="text-xs uppercase tracking-wider font-medium text-zinc-500 dark:text-zinc-400 mb-2">Proofs</div>
                <div class="flex items-center justify-between gap-3">
                    <flux:badge :color="$pendingProofs > 0 ? 'amber' : 'zinc'" size="lg" inset="top bottom">{{ $pendingProofs }}</flux:badge>
                    @if($pendingProofs > 0)
                        <flux:button wire:click="proofPendingPhotos" wire:loading.attr="disabled" wire:target="proofPendingPhotos" :disabled="isset($processing_status) && $processing_status['ready']['proofs'] === 0" size="xs" variant="primary">Generate</flux:button>
                    @else
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">All generated</span>
                    @endif
                </div>
            </div>

            {{-- Web Images --}}
            <div class="p-4">
                <div class="flex items-center justify-between mb-2">
                    <div class="text-xs uppercase tracking-wider font-medium text-zinc-500 dark:text-zinc-400">Web Images</div>
                    @if(!$web_images_enabled)
                        <flux:tooltip content="Disabled in settings"><flux:icon name="exclamation-triangle" class="size-3.5 text-amber-500" /></flux:tooltip>
                    @endif
                </div>
                <div class="flex items-center justify-between gap-3">
                    <flux:badge :color="$pendingWeb > 0 && $web_images_enabled ? 'amber' : 'zinc'" size="lg" inset="top bottom">{{ $pendingWeb }}</flux:badge>
                    @if(!$web_images_enabled)
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">Disabled</span>
                    @elseif($pendingWeb > 0)
                        <flux:button wire:click="webImagePendingPhotos" wire:loading.attr="disabled" wire:target="webImagePendingPhotos" :disabled="isset($processing_status) && $processing_status['ready']['web'] === 0" size="xs" variant="primary">Generate</flux:button>
                    @else
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">All generated</span>
                    @endif
                </div>
            </div>

            {{-- Highres --}}
            <div class="p-4">
                <div class="flex items-center justify-between mb-2">
                    <div class="text-xs uppercase tracking-wider font-medium text-zinc-500 dark:text-zinc-400">Highres Images</div>
                    @if(!$highres_images_enabled)
                        <flux:tooltip content="Disabled in settings"><flux:icon name="exclamation-triangle" class="size-3.5 text-amber-500" /></flux:tooltip>
                    @endif
                </div>
                <div class="flex items-center justify-between gap-3">
                    <flux:badge :color="$pendingHighres > 0 && $highres_images_enabled ? 'amber' : 'zinc'" size="lg" inset="top bottom">{{ $pendingHighres }}</flux:badge>
                    @if(!$highres_images_enabled)
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">Disabled</span>
                    @elseif($pendingHighres > 0)
                        <flux:button wire:click="highresImagePendingPhotos" wire:loading.attr="disabled" wire:target="highresImagePendingPhotos" :disabled="isset($processing_status) && $processing_status['ready']['highres'] === 0" size="xs" variant="primary">Generate</flux:button>
                    @else
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">All generated</span>
                    @endif
                </div>
            </div>
        </div>

        {{-- Uploads --}}
        <div class="px-5 py-4 space-y-3">
            <flux:heading size="base">Uploads</flux:heading>

            {{-- Dry-run check --}}
            <div class="flex items-start justify-between gap-3">
                <div x-data="{ expanded: false }" class="text-sm flex-1">
                    <button type="button" @click="expanded = !expanded"
                            class="text-left text-zinc-700 dark:text-zinc-300 hover:text-zinc-900 dark:hover:text-white">
                        Check web server uploads
                        <span class="text-zinc-400 dark:text-zinc-500 underline-offset-2 underline ml-1 text-xs">
                            <span x-show="!expanded">more info</span>
                            <span x-show="expanded" x-cloak>hide</span>
                        </span>
                    </button>
                    <div x-show="expanded" x-cloak class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        Performs a dry-run that updates the local upload status of files in the database.
                        Doesn't actually upload anything. Fast.
                    </div>
                </div>
                <flux:button wire:click="checkProofAndWebImageUploads" size="xs">Check</flux:button>
            </div>

            <div class="border-t border-zinc-200 dark:border-white/10"></div>

            @if($totalPendingUploads > 0)
                <div class="flex items-center justify-between gap-3">
                    <div class="text-sm text-zinc-700 dark:text-zinc-300 flex flex-wrap items-center gap-x-2 gap-y-1">
                        @if($pendingProofUploads > 0)
                            <span class="inline-flex items-center gap-1.5">
                                <flux:badge color="amber" size="sm">{{ $pendingProofUploads }}</flux:badge>
                                proofs
                            </span>
                        @endif
                        @if($pendingWebUploads > 0)
                            <span class="inline-flex items-center gap-1.5">
                                <flux:badge color="amber" size="sm">{{ $pendingWebUploads }}</flux:badge>
                                web images
                            </span>
                        @endif
                        @if($pendingHighresUploads > 0)
                            <span class="inline-flex items-center gap-1.5">
                                <flux:badge color="amber" size="sm">{{ $pendingHighresUploads }}</flux:badge>
                                highres images
                            </span>
                        @endif
                        <span class="text-zinc-500 dark:text-zinc-400">pending upload</span>
                    </div>
                    <flux:button wire:click="uploadPendingProofsAndWebImages" size="xs" variant="primary">Upload</flux:button>
                </div>
            @else
                <div class="flex items-center gap-2 px-3 py-2 rounded-md
                            bg-emerald-50 dark:bg-emerald-500/10
                            border border-emerald-200/60 dark:border-emerald-500/20
                            text-sm text-emerald-900 dark:text-emerald-200/90">
                    <flux:icon name="check-circle" class="size-4 text-emerald-600 dark:text-emerald-400 shrink-0" />
                    No generated images are waiting for upload.
                </div>
            @endif
        </div>

        {{-- Regenerate / reset --}}
        @if($importedCount > 0)
            <div class="px-5 py-4 border-t border-zinc-200 dark:border-white/10 space-y-2">
                <div class="text-xs uppercase tracking-wider font-medium text-zinc-500 dark:text-zinc-400 mb-1">
                    Force operations
                </div>
                <flux:text class="!text-xs !mb-3">
                    Ignore existing files and regenerate / re-upload everything that has been imported.
                </flux:text>

                <div class="flex items-center justify-between gap-3">
                    <span class="text-sm text-zinc-700 dark:text-zinc-300">
                        Regenerate proofs on
                        <flux:badge color="zinc" size="sm">{{ number_format($importedCount) }}</flux:badge>
                        photos
                    </span>
                    <flux:button wire:click="regenerateProofs" size="xs" variant="ghost">Regenerate</flux:button>
                </div>

                <div class="flex items-center justify-between gap-3">
                    <span class="text-sm text-zinc-700 dark:text-zinc-300">
                        Regenerate web images on
                        <flux:badge color="zinc" size="sm">{{ number_format($importedCount) }}</flux:badge>
                        photos
                    </span>
                    @if($web_images_enabled)
                        <flux:button wire:click="regenerateWebImages" size="xs" variant="ghost">Regenerate</flux:button>
                    @else
                        <span class="text-xs text-amber-600 dark:text-amber-400">Disabled in settings</span>
                    @endif
                </div>

                <div class="flex items-center justify-between gap-3">
                    <span class="text-sm text-zinc-700 dark:text-zinc-300">
                        Regenerate highres on
                        <flux:badge color="zinc" size="sm">{{ number_format($importedCount) }}</flux:badge>
                        photos
                    </span>
                    @if($highres_images_enabled)
                        <flux:button wire:click="regenerateHighresImages" size="xs" variant="ghost">Regenerate</flux:button>
                    @else
                        <span class="text-xs text-amber-600 dark:text-amber-400">Disabled in settings</span>
                    @endif
                </div>

                @if($importedCount - $pendingProofs > 0)
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm text-zinc-700 dark:text-zinc-300">
                            Force-upload all
                            <flux:badge color="zinc" size="sm">{{ number_format($importedCount - $pendingProofs) }}</flux:badge>
                            images
                        </span>
                        <flux:button wire:click="uploadPendingProofsAndWebImages" size="xs" variant="ghost">Upload all</flux:button>
                    </div>
                @endif

                <div class="flex items-center justify-between gap-3 pt-2 border-t border-zinc-200 dark:border-white/10 mt-2">
                    <span class="text-sm text-rose-700 dark:text-rose-400">
                        Reset all
                        <flux:badge color="rose" size="sm">{{ number_format($importedCount) }}</flux:badge>
                        imported photos
                    </span>
                    <flux:button
                        wire:confirm="Reset all imported photos? This deletes generated proofs/web/highres images, moves originals back to the import folder, and removes the database records."
                        wire:click="resetPhotos"
                        size="xs"
                        variant="danger"
                    >
                        Reset
                    </flux:button>
                </div>
            </div>
        @endif
    </flux:card>
</div>
