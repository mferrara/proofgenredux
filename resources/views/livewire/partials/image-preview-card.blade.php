{{--
    Image preview card — shared by Large/Small thumbnails, Web image, Highres image.

    @props:
        title           - "Large Thumbnail Preview"
        preview         - URL to enhanced preview (or null while loading)
        unenhanced      - URL to unenhanced preview (or null when not generated)
        info            - file info dict
        inputSettings   - dict
        enhancementInfo - dict|null
        processingTime  - float|null
        tempPath        - dot-path into $tempThumbnailValues, e.g. 'thumbnails.large' or 'web_images'
        placeholderSize - tailwind width/height utility for empty placeholder
        showRegenerate  - bool, show the regenerate button overlay (only on thumbnail tabs)
        sampleImagePath - bool/null
        enhancementOn   - bool, current tab has enhancement applied
--}}
@props([
    'title',
    'preview',
    'unenhanced',
    'info',
    'inputSettings',
    'enhancementInfo',
    'processingTime',
    'tempPath',
    'placeholderSize' => 'w-[600px] h-[600px]',
    'showRegenerate' => false,
    'sampleImagePath' => null,
    'enhancementOn' => false,
    'previewError' => null,
])

@if($sampleImagePath)
    <flux:card class="mb-6 !p-6">
        <div class="flex items-center justify-between mb-4">
            <flux:heading size="lg">{{ $title }}</flux:heading>
            @if($processingTime)
                <flux:badge color="zinc" size="sm">
                    {{ number_format($processingTime * 1000) }}ms
                </flux:badge>
            @endif
        </div>

        @if($previewError)
            <div role="alert" class="space-y-3">
                <flux:heading size="sm">Preview could not be generated</flux:heading>
                <flux:text>{{ $previewError }}</flux:text>
                <flux:button wire:click="generateThumbnailPreviews" wire:loading.attr="disabled" size="sm">
                    Retry preview
                </flux:button>
            </div>
        @elseif($preview)
            <div class="relative inline-block" x-data="{ showUnenhanced: false }">
                <img x-bind:src="showUnenhanced && @js($unenhanced) ? @js($unenhanced) : @js($preview)"
                     alt="{{ $title }}"
                     class="border border-zinc-300 dark:border-zinc-600 rounded-md transition-all duration-200 max-w-full"
                     x-bind:class="{ 'border-amber-500 dark:border-amber-500': showUnenhanced }"
                     wire:loading.class="opacity-50"
                     wire:target="updatePreview,updateActiveTab,generateThumbnailPreviews">

                <div wire:loading.delay wire:target="updatePreview,updateActiveTab,generateThumbnailPreviews"
                     class="absolute inset-0 flex items-center justify-center">
                    <flux:icon.loading class="w-8 h-8 text-blue-500" />
                </div>

                @if($enhancementOn)
                    <div class="absolute top-2 right-2 flex items-center gap-2">
                        @if($showRegenerate)
                            <flux:button
                                type="button"
                                size="xs"
                                variant="filled"
                                icon="arrow-path"
                                tooltip="Regenerate preview"
                                wire:click="generateThumbnailPreviews"
                                wire:loading.attr="disabled"
                            />
                        @endif
                        <div x-on:mouseenter="showUnenhanced = true"
                             x-on:mouseleave="showUnenhanced = false"
                             class="inline-flex items-center px-2.5 py-1 text-xs font-medium text-white bg-blue-600 rounded-md cursor-pointer select-none transition hover:bg-blue-700">
                            <flux:icon.sparkles variant="mini" class="mr-1 size-3.5" />
                            <span x-text="showUnenhanced ? 'Original' : 'Enhanced'"></span>
                        </div>
                    </div>
                @endif
            </div>

            @if($info)
                @include('livewire.partials.preview-info-label', [
                    'inputSettings' => $inputSettings,
                    'fileInfo' => $info,
                    'enhancementInfo' => $enhancementInfo,
                    'processingTime' => $processingTime,
                ])
            @endif
        @else
            <div class="{{ $placeholderSize }} max-w-full bg-zinc-100 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-md flex items-center justify-center">
                <span wire:loading.remove wire:target="updatePreview,updateActiveTab,generateThumbnailPreviews,previewWatermarkEnabled">Preview not generated yet.</span>
                <flux:icon.loading wire:loading wire:target="updatePreview,updateActiveTab,generateThumbnailPreviews,previewWatermarkEnabled" class="w-8 h-8 text-zinc-500" />
            </div>
        @endif

        {{-- Live preview controls (separate from persisted settings below). --}}
        <div class="mt-5 pt-4 border-t border-zinc-200 dark:border-zinc-700">
            <div class="text-xs uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-2 font-medium">
                Try values <span class="text-zinc-400 dark:text-zinc-500 normal-case tracking-normal font-normal">— preview only, save below to persist</span>
            </div>
            <div class="flex flex-wrap items-end gap-3">
                <flux:field class="!gap-1">
                    <flux:label class="text-xs">Width</flux:label>
                    <flux:input
                        type="text"
                        wire:model="tempThumbnailValues.{{ $tempPath }}.width"
                        wire:change="updatePreview"
                        class="w-24"
                    />
                </flux:field>
                <flux:field class="!gap-1">
                    <flux:label class="text-xs">Height</flux:label>
                    <flux:input
                        type="text"
                        wire:model="tempThumbnailValues.{{ $tempPath }}.height"
                        wire:change="updatePreview"
                        class="w-24"
                    />
                </flux:field>
                <flux:field class="!gap-1">
                    <flux:label class="text-xs">Quality</flux:label>
                    <flux:input
                        type="text"
                        wire:model="tempThumbnailValues.{{ $tempPath }}.quality"
                        wire:change="updatePreview"
                        class="w-24"
                    />
                </flux:field>
            </div>
        </div>
    </flux:card>
@endif
