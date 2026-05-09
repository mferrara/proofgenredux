<div class="px-6 lg:px-10 py-6 max-w-[1400px] mx-auto" wire:poll.5s
     x-data='{
         thumbnailSize: localStorage.getItem("thumbnailSize") || "small",
         viewMode: localStorage.getItem("viewMode") || "list",
         setThumbnailSize(size) {
             this.thumbnailSize = size;
             localStorage.setItem("thumbnailSize", size);
             $wire.set("thumbnailSize", size);
         },
         setViewMode(mode) {
             this.viewMode = mode;
             localStorage.setItem("viewMode", mode);
             $wire.set("viewMode", mode);
         }
     }'
     x-init='
         $wire.set("thumbnailSize", thumbnailSize);
         $wire.set("viewMode", viewMode);
     '>

    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <flux:button
                href="/show/{{ $show }}"
                size="sm"
                variant="ghost"
                square
                icon="chevron-left"
                tooltip="Back to {{ $show }}"
            />
            <div>
                <div class="text-xs uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                    <a href="/show/{{ $show }}" class="hover:underline">{{ $show }}</a> · Class
                </div>
                <flux:heading size="xl" level="1" class="!text-3xl !font-semibold tracking-tight">{{ $class }}</flux:heading>
            </div>
            <div wire:loading class="ml-2">
                <flux:badge color="sky" size="sm" class="animate-pulse">Working…</flux:badge>
            </div>
        </div>

        <div class="flex items-center gap-3">
            @if(isset($flash_message) && strlen($flash_message))
                <flux:badge color="emerald" size="sm">{{ $flash_message }}</flux:badge>
            @endif
            @if(($open_issue_count ?? 0) > 0)
                <a href="{{ route('photo-issues', ['show_class_id' => $show_class->id]) }}">
                    <flux:badge color="amber" size="sm" icon="exclamation-triangle">
                        {{ $open_issue_count }} {{ str('issue')->plural($open_issue_count) }}
                    </flux:badge>
                </a>
            @endif
            <flux:button
                size="sm"
                variant="ghost"
                square
                icon="folder-open"
                tooltip="Open class folder in Finder"
                wire:click="openFolder('{{ $show_class->full_path }}')"
            />
        </div>
    </div>

    {{-- Status snapshot + actions --}}
    <div class="grid grid-cols-12 gap-4 mb-8">
        @include('components.partials.photo-process-status-table')
        @include('components.partials.action-panel')
    </div>

    {{-- Folder layout --}}
    <flux:card class="!p-0 overflow-hidden mb-8">
        <div class="px-5 py-3 border-b border-zinc-200 dark:border-white/10 bg-zinc-50/50 dark:bg-white/[0.02]">
            <flux:heading size="base">Folder layout</flux:heading>
            <flux:text class="!text-xs mt-0.5">Where files are read from and written to for this class.</flux:text>
        </div>
        <div class="px-5 py-3 divide-y divide-zinc-100 dark:divide-white/5">
            @include('livewire.partials.path-row', [
                'label' => 'New photos',
                'color' => 'amber',
                'description' => 'Drop new images here to be imported.',
                'path' => $show_class->full_path,
            ])
            @include('livewire.partials.path-row', [
                'label' => 'Imported',
                'color' => 'emerald',
                'description' => 'Originals are moved here once imported.',
                'path' => $show_class->full_originals_path,
            ])
            @include('livewire.partials.path-row', [
                'label' => 'Proofs',
                'color' => 'sky',
                'description' => 'Generated proofs.',
                'path' => $show_class->full_proofs_path,
            ])
            @include('livewire.partials.path-row', [
                'label' => 'Web images',
                'color' => 'cyan',
                'description' => 'Generated web images.',
                'path' => $show_class->full_web_images_path,
            ])
            @include('livewire.partials.path-row', [
                'label' => 'Highres',
                'color' => 'purple',
                'description' => 'Generated high-resolution images.',
                'path' => $show_class->full_highres_images_path,
            ])
        </div>
    </flux:card>

    {{-- Pending imports --}}
    @if($photos_pending_import && count($photos_pending_import))
        <flux:card class="mb-8">
            <flux:heading size="base" class="mb-1">Images to be imported</flux:heading>
            <flux:text class="!text-xs mb-4">
                {{ count($photos_pending_import) }} {{ str('image')->plural(count($photos_pending_import)) }} in the class folder, not yet imported.
            </flux:text>
            @include('components.partials.images-table', ['images' => $photos_pending_import, 'actions' => []])
        </flux:card>
    @endif

    {{-- Imported photos --}}
    <flux:card>
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <div>
                <flux:heading size="base">
                    Imported Photos
                    @if($photos_imported->count())
                        <span class="ml-1 text-zinc-500 dark:text-zinc-400 font-normal">({{ number_format($photos_imported->count()) }})</span>
                    @endif
                </flux:heading>
            </div>

            @if(! $photos_imported->count())
                <flux:badge color="zinc" size="sm">No images imported yet</flux:badge>
            @else
                <div class="flex items-center gap-2">
                    <flux:button.group>
                        <flux:button x-on:click="setThumbnailSize('small')" :variant="$thumbnailSize === 'small' ? 'filled' : 'outline'" size="sm" tooltip="Small thumbnails">S</flux:button>
                        <flux:button x-on:click="setThumbnailSize('medium')" :variant="$thumbnailSize === 'medium' ? 'filled' : 'outline'" size="sm" tooltip="Medium thumbnails">M</flux:button>
                        <flux:button x-on:click="setThumbnailSize('large')" :variant="$thumbnailSize === 'large' ? 'filled' : 'outline'" size="sm" tooltip="Large thumbnails">L</flux:button>
                    </flux:button.group>

                    <flux:button.group>
                        <flux:button x-on:click="setViewMode('list')" :variant="$viewMode === 'list' ? 'filled' : 'outline'" size="sm" icon="list-bullet" tooltip="List view" />
                        <flux:button x-on:click="setViewMode('grid')" :variant="$viewMode === 'grid' ? 'filled' : 'outline'" size="sm" icon="squares-2x2" tooltip="Grid view" />
                    </flux:button.group>
                </div>
            @endif
        </div>

        @if($photos->count())
            @if($viewMode === 'list')
                @include('components.partials.photos-table', ['photos' => $photos, 'actions' => [], 'display_thumbnail' => true, 'details' => true, 'selectedPhotos' => $selectedPhotos, 'thumbnailSize' => $thumbnailSize])
            @else
                @include('components.partials.photos-grid', ['photos' => $photos, 'selectedPhotos' => $selectedPhotos, 'thumbnailSize' => $thumbnailSize])
            @endif
        @else
            <div class="py-10 text-center">
                <flux:icon name="photo" class="size-10 mx-auto text-zinc-400 dark:text-zinc-600 mb-2" />
                <flux:text>No imported photos. Drop new images into the New photos folder above to get started.</flux:text>
            </div>
        @endif
    </flux:card>

    {{-- Move modal --}}
    <flux:modal wire:model="showMoveModal" name="move-photos">
        <div class="space-y-5">
            <flux:heading size="lg">Move photos to another class</flux:heading>
            <flux:text>Moving {{ count($selectedPhotos) }} {{ str('photo')->plural(count($selectedPhotos)) }} to another class will relocate originals plus any generated proofs / web / highres images.</flux:text>

            <flux:field>
                <flux:label>Target class</flux:label>
                <flux:select wire:model="targetClass">
                    <flux:select.option value="">Select a class…</flux:select.option>
                    @foreach($show_class->show->classes as $cls)
                        @if($cls->id !== $show_class->id)
                            <flux:select.option value="{{ $cls->id }}">{{ $cls->name }}</flux:select.option>
                        @endif
                    @endforeach
                </flux:select>
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showMoveModal', false)">Cancel</flux:button>
                <flux:button variant="primary" wire:click="moveSelectedPhotos" icon="arrows-right-left">Move photos</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Delete modal --}}
    <flux:modal wire:model="showDeleteModal" name="delete-photos">
        <div class="space-y-5">
            <flux:heading size="lg">Delete photos</flux:heading>

            <div class="flex gap-3 px-4 py-3 rounded-lg
                        bg-rose-50 dark:bg-rose-500/10
                        border border-rose-200/60 dark:border-rose-500/20">
                <flux:icon name="exclamation-triangle" class="size-5 text-rose-600 dark:text-rose-400 shrink-0 mt-0.5" />
                <div class="text-sm text-rose-900 dark:text-rose-200/90">
                    You are about to delete {{ count($selectedPhotos) }} {{ str('photo')->plural(count($selectedPhotos)) }}. This cannot be undone.
                </div>
            </div>

            <flux:checkbox wire:model="deleteFiles" label="Also delete files (originals, proofs, web images, highres images)" />

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showDeleteModal', false)">Cancel</flux:button>
                <flux:button variant="danger" wire:click="deleteSelectedPhotos" icon="trash">Delete photos</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Image preview modal --}}
    <flux:modal wire:model="showImageModal" name="image-preview" class="max-w-4xl">
        @if($modalImageData)
            <div class="space-y-4">
                <flux:heading size="lg">
                    Proof <span class="font-mono">#{{ $modalImageData['photo']->proof_number }}</span>
                </flux:heading>

                <div class="rounded-lg p-3 bg-zinc-100 dark:bg-zinc-800 border border-zinc-200 dark:border-white/10">
                    <img src="{{ $modalImageData['image'] }}"
                         alt="Proof #{{ $modalImageData['photo']->proof_number }}"
                         class="max-w-full h-auto mx-auto rounded">
                </div>

                <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">File type</dt>
                        <dd class="text-zinc-900 dark:text-white font-medium">{{ strtoupper($modalImageData['photo']->file_type) }}</dd>
                    </div>
                    @if($modalImageData['photo']->metadata)
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">Dimensions</dt>
                            <dd class="text-zinc-900 dark:text-white font-medium">
                                {{ $modalImageData['photo']->metadata->width }} × {{ $modalImageData['photo']->metadata->height }} px
                            </dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">File size</dt>
                            <dd class="text-zinc-900 dark:text-white font-medium">
                                {{ $this->humanReadableFilesize($modalImageData['photo']->metadata->file_size) }}
                            </dd>
                        </div>
                        @if($modalImageData['photo']->metadata->camera_model)
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">Camera</dt>
                                <dd class="text-zinc-900 dark:text-white font-medium">{{ $modalImageData['photo']->metadata->camera_model }}</dd>
                            </div>
                        @endif
                    @endif
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">Imported</dt>
                        <dd class="text-zinc-900 dark:text-white font-medium font-mono text-xs">
                            {{ $modalImageData['photo']->created_at->format('Y-m-d H:i:s') }}
                        </dd>
                    </div>
                    @if($modalImageData['photo']->proofs_generated_at)
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">Proofs generated</dt>
                            <dd class="text-zinc-900 dark:text-white font-medium font-mono text-xs">
                                {{ $modalImageData['photo']->proofs_generated_at->format('Y-m-d H:i:s') }}
                            </dd>
                        </div>
                    @endif
                </dl>
            </div>
        @endif
    </flux:modal>
</div>
