<div class="px-8 py-6" wire:poll.5s>
    <div class="max-w-6xl mx-auto">
        <div class="flex justify-between items-center mb-6">
            <div class="flex items-center gap-3">
                <a href="/show/{{ $show }}" class="text-indigo-400 hover:text-indigo-300" title="Back to {{ $show }}">
                    <flux:icon name="chevron-left" variant="outline" />
                </a>
                <flux:heading size="xl">
                    Show: <a href="/show/{{ $show }}" class="text-indigo-500 hover:text-indigo-400 hover:underline">{{ $show }}</a> /
                    <span class="text-indigo-300">Class: {{ $class }}</span>
                </flux:heading>

                <div wire:loading class="ml-3">
                    <flux:badge variant="solid" color="blue" size="sm" class="animate-pulse">
                        Working...
                    </flux:badge>
                </div>
            </div>

            @if(isset($flash_message) && strlen($flash_message))
                <flux:badge variant="solid" color="green">{{ $flash_message }}</flux:badge>
            @endif
        </div>

        <div class="grid grid-cols-12 gap-6 mb-8">
            <!-- Summary Stats -->
            @include('components.partials.photo-process-status-table')

            <!-- Action Panel -->
            @include('components.partials.action-panel')
        </div>

        <flux:card class="mb-8">
            <flux:heading size="lg" class="mb-4">Folder Information</flux:heading>
            <ul class="list-disc pl-5 space-y-2 text-gray-400">
                <li>
                    <div class="flex flex-row items-center gap-x-1">
                        <div class="flex flex-row items-center gap-x-1">
                            <div><flux:badge color="lime">New Photos</flux:badge> can be imported into to this class by putting the image in the</div>
                            <span class="text-indigo-400 px-2 py-1 bg-gray-400/10 shadow-md">{{ $show_class->full_path }}</span>
                            <div>directory</div>
                        </div>
                        <flux:tooltip content="Copy path to clipboard">
                            <div
                                class="text-indigo-300 hover:text-indigo-400 hover:cursor-pointer hover:bg-gray-50/10 rounded-sm p-1"
                                x-data="{ copied: false }"
                                x-on:click="
                                    const el = document.createElement('textarea');
                                    el.value = '{{ $show_class->full_path }}';
                                    el.setAttribute('readonly', '');
                                    el.style.position = 'absolute';
                                    el.style.left = '-9999px';
                                    document.body.appendChild(el);
                                    el.select();
                                    document.execCommand('copy');
                                    document.body.removeChild(el);
                                    copied = true;
                                    setTimeout(() => copied = false, 2000);
                                "
                            >
                                <template x-if="!copied">
                                    <flux:icon.document-duplicate class="size-5" />
                                </template>
                                <template x-if="copied">
                                    <flux:icon.check class="size-5 !text-emerald-500" />
                                </template>
                            </div>
                        </flux:tooltip>
                        <flux:tooltip content="Open path in finder">
                            <div class="text-indigo-300 hover:text-indigo-400 hover:cursor-pointer hover:bg-gray-50/10 rounded-sm p-1"
                                 wire:click="openFolder('{{ $show_class->full_path }}')">
                                <flux:icon.arrow-top-right-on-square class="size-5" />
                            </div>
                        </flux:tooltip>
                    </div>
                </li>
                <li>
                    <div class="flex flex-row items-center gap-x-1">
                        <div class="flex flex-row items-center gap-x-1">
                            <div><flux:badge color="teal">Imported Photos</flux:badge> are moved from the base class directory into the</div>
                            <span class="text-indigo-400 px-2 py-1 bg-gray-400/10 shadow-md">{{ $show_class->full_originals_path }}</span>
                            <div>directory</div>
                        </div>
                        <flux:tooltip content="Copy path to clipboard">
                            <div
                                class="text-indigo-300 hover:text-indigo-400 hover:cursor-pointer hover:bg-gray-50/10 rounded-sm p-1"
                                x-data="{ copied: false }"
                                x-on:click="
                                    const el = document.createElement('textarea');
                                    el.value = '{{ $show_class->full_originals_path }}';
                                    el.setAttribute('readonly', '');
                                    el.style.position = 'absolute';
                                    el.style.left = '-9999px';
                                    document.body.appendChild(el);
                                    el.select();
                                    document.execCommand('copy');
                                    document.body.removeChild(el);
                                    copied = true;
                                    setTimeout(() => copied = false, 2000);
                                "
                            >
                                <template x-if="!copied">
                                    <flux:icon.document-duplicate class="size-5" />
                                </template>
                                <template x-if="copied">
                                    <flux:icon.check class="size-5 !text-emerald-500" />
                                </template>
                            </div>
                        </flux:tooltip>
                        <flux:tooltip content="Open path in finder">
                            <div class="text-indigo-300 hover:text-indigo-400 hover:cursor-pointer hover:bg-gray-50/10 rounded-sm p-1"
                                 wire:click="openFolder('{{ $show_class->full_originals_path }}')">
                                <flux:icon.arrow-top-right-on-square class="size-5" />
                            </div>
                        </flux:tooltip>
                    </div>
                </li>
                <li>
                    <div class="flex flex-row items-center gap-x-1">
                        <div class="flex flex-row items-center gap-x-1">
                            <div><flux:badge color="sky">Proofs</flux:badge> that have been generated for imported photos can be found in the</div>
                            <span class="text-indigo-400 px-2 py-1 bg-gray-400/10 shadow-md">{{ $show_class->full_proofs_path }}</span>
                            <div>directory</div>
                        </div>
                        <flux:tooltip content="Copy path to clipboard">
                            <div
                                class="text-indigo-300 hover:text-indigo-400 hover:cursor-pointer hover:bg-gray-50/10 rounded-sm p-1"
                                x-data="{ copied: false }"
                                x-on:click="
                                    const el = document.createElement('textarea');
                                    el.value = '{{ $show_class->full_proofs_path }}';
                                    el.setAttribute('readonly', '');
                                    el.style.position = 'absolute';
                                    el.style.left = '-9999px';
                                    document.body.appendChild(el);
                                    el.select();
                                    document.execCommand('copy');
                                    document.body.removeChild(el);
                                    copied = true;
                                    setTimeout(() => copied = false, 2000);
                                "
                            >
                                <template x-if="!copied">
                                    <flux:icon.document-duplicate class="size-5" />
                                </template>
                                <template x-if="copied">
                                    <flux:icon.check class="size-5 !text-emerald-500" />
                                </template>
                            </div>
                        </flux:tooltip>
                        <flux:tooltip content="Open path in finder">
                            <div class="text-indigo-300 hover:text-indigo-400 hover:cursor-pointer hover:bg-gray-50/10 rounded-sm p-1"
                                 wire:click="openFolder('{{ $show_class->full_proofs_path }}')">
                                <flux:icon.arrow-top-right-on-square class="size-5" />
                            </div>
                        </flux:tooltip>
                    </div>
                </li>
                <li>
                    <div class="flex flex-row items-center gap-x-1">
                        <div class="flex flex-row items-center gap-x-1">
                            <div><flux:badge color="indigo">Web Images</flux:badge> generated for this classes imported photos are located at &nbsp;&nbsp;&nbsp;&nbsp;</div>
                            <span class="text-indigo-400 px-2 py-1 bg-gray-400/10 shadow-md">{{ $show_class->full_web_images_path }}</span>
                        </div>
                        <flux:tooltip content="Copy path to clipboard">
                            <div
                                class="text-indigo-300 hover:text-indigo-400 hover:cursor-pointer hover:bg-gray-50/10 rounded-sm p-1"
                                x-data="{ copied: false }"
                                x-on:click="
                                    const el = document.createElement('textarea');
                                    el.value = '{{ $show_class->full_web_images_path }}';
                                    el.setAttribute('readonly', '');
                                    el.style.position = 'absolute';
                                    el.style.left = '-9999px';
                                    document.body.appendChild(el);
                                    el.select();
                                    document.execCommand('copy');
                                    document.body.removeChild(el);
                                    copied = true;
                                    setTimeout(() => copied = false, 2000);
                                "
                            >
                                <template x-if="!copied">
                                    <flux:icon.document-duplicate class="size-5" />
                                </template>
                                <template x-if="copied">
                                    <flux:icon.check class="size-5 !text-emerald-500" />
                                </template>
                            </div>
                        </flux:tooltip>
                        <flux:tooltip content="Open path in finder">
                            <div class="text-indigo-300 hover:text-indigo-400 hover:cursor-pointer hover:bg-gray-50/10 rounded-sm p-1"
                                 wire:click="openFolder('{{ $show_class->full_web_images_path }}')">
                                <flux:icon.arrow-top-right-on-square class="size-5" />
                            </div>
                        </flux:tooltip>
                    </div>
                </li>
                <li>
                    <div class="flex flex-row items-center gap-x-1">
                        <div class="flex flex-row items-center gap-x-1">
                            <div><flux:badge color="purple">Highres Images</flux:badge> generated for this classes imported photos are located at</div>
                            <span class="text-indigo-400 px-2 py-1 bg-gray-400/10 shadow-md">{{ $show_class->full_highres_images_path }}</span>
                        </div>
                        <flux:tooltip content="Copy path to clipboard">
                            <div
                                class="text-indigo-300 hover:text-indigo-400 hover:cursor-pointer hover:bg-gray-50/10 rounded-sm p-1"
                                x-data="{ copied: false }"
                                x-on:click="
                                    const el = document.createElement('textarea');
                                    el.value = '{{ $show_class->full_highres_images_path }}';
                                    el.setAttribute('readonly', '');
                                    el.style.position = 'absolute';
                                    el.style.left = '-9999px';
                                    document.body.appendChild(el);
                                    el.select();
                                    document.execCommand('copy');
                                    document.body.removeChild(el);
                                    copied = true;
                                    setTimeout(() => copied = false, 2000);
                                "
                            >
                                <template x-if="!copied">
                                    <flux:icon.document-duplicate class="size-5" />
                                </template>
                                <template x-if="copied">
                                    <flux:icon.check class="size-5 !text-emerald-500" />
                                </template>
                            </div>
                        </flux:tooltip>
                        <flux:tooltip content="Open path in finder">
                            <div class="text-indigo-300 hover:text-indigo-400 hover:cursor-pointer hover:bg-gray-50/10 rounded-sm p-1"
                                 wire:click="openFolder('{{ $show_class->full_highres_images_path }}')">
                                <flux:icon.arrow-top-right-on-square class="size-5" />
                            </div>
                        </flux:tooltip>
                    </div>
                </li>
            </ul>
        </flux:card>

        @if(($photos_pending_import && count($photos_pending_import)) || $photos_pending_proofs->count())
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                @if($photos_pending_import && count($photos_pending_import))
                    <flux:card>
                        <flux:heading size="lg" class="mb-4">
                            Images to be Imported ({{ count($photos_pending_import) }})
                        </flux:heading>

                        <div class="text-gray-500 text-sm">
                            These are images in the base class folder, not yet imported.
                        </div>

                        @include('components.partials.images-table', ['images' => $photos_pending_import, 'actions' => []])
                    </flux:card>
                @endif
            </div>
        @endif

        <flux:card>
            <div class="flex justify-between items-center mb-4">
                <flux:heading size="xl">
                    Imported Photos {{ $photos_imported->count() ? '(' . $photos_imported->count() . ')' : '' }}
                </flux:heading>

                @if(! $photos_imported->count())
                    <flux:badge variant="solid" color="amber" size="sm">No images imported yet</flux:badge>
                @endif
            </div>

            <p class="text-gray-400 mb-6">
                These images have been imported but may still require additional processing: proof generation,
                web image generation, and uploading depending on your configuration settings.
            </p>

            @if($photos->count())
                @include('components.partials.photos-table', ['photos' => $photos, 'actions' => [], 'display_thumbnail' => true, 'details' => true, 'selectedPhotos' => $selectedPhotos])
            @else
                <div class="py-8 text-center">
                    <div class="mb-4">
                        <flux:icon name="photo" variant="outline" class="text-gray-500 size-12 mx-auto" />
                    </div>
                    <p class="text-gray-500">No processed images found. Import images to begin.</p>
                </div>
            @endif
        </flux:card>
    </div>

    {{-- Move Modal --}}
    <flux:modal wire:model="showMoveModal" name="move-photos">
        <flux:heading size="lg">Move Photos to Another Class</flux:heading>

        <div class="mt-4">
            <p class="text-gray-400 mb-4">
                Moving {{ count($selectedPhotos) }} photos to another class.
            </p>

            <flux:select wire:model="targetClass" label="Target Class">
                <flux:select.option value="">Select a class...</flux:select.option>
                @foreach($show_class->show->classes as $class)
                    @if($class->id !== $show_class->id)
                        <flux:select.option value="{{ $class->id }}">{{ $class->name }}</flux:select.option>
                    @endif
                @endforeach
            </flux:select>

            <div class="mt-4 text-sm text-gray-500">
                <p>This will move:</p>
                <ul class="list-disc list-inside mt-2">
                    <li>Original files</li>
                    <li>Generated proofs (if any)</li>
                    <li>Web images (if any)</li>
                    <li>Highres images (if any)</li>
                </ul>
            </div>
        </div>

        <div class="flex justify-end gap-3 mt-6">
            <flux:button variant="ghost" wire:click="$set('showMoveModal', false)">
                Cancel
            </flux:button>
            <flux:button variant="primary" wire:click="moveSelectedPhotos">
                Move Photos
            </flux:button>
        </div>
    </flux:modal>

    {{-- Delete Modal --}}
    <flux:modal wire:model="showDeleteModal" name="delete-photos">
        <flux:heading size="lg">Delete Photos</flux:heading>

        <div class="mt-4">
            <flux:badge color="red" size="lg">
                Warning: This action cannot be undone
            </flux:badge>

            <p class="text-gray-400 mt-4">
                You are about to delete {{ count($selectedPhotos) }} photos.
            </p>

            <div class="mt-4">
                <flux:checkbox wire:model="deleteFiles" label="Also delete files (originals, proofs, web images, highres images)" />
            </div>
        </div>

        <div class="flex justify-end gap-3 mt-6">
            <flux:button variant="ghost" wire:click="$set('showDeleteModal', false)">
                Cancel
            </flux:button>
            <flux:button variant="danger" wire:click="deleteSelectedPhotos">
                Delete Photos
            </flux:button>
        </div>
    </flux:modal>

    {{-- Image Preview Modal --}}
    <flux:modal wire:model="showImageModal" name="image-preview" class="max-w-4xl">
        @if($modalImageData)
            <div class="space-y-4">
                <flux:heading size="lg">
                    Proof #{{ $modalImageData['photo']->proof_number }}
                </flux:heading>

                <div class="bg-zinc-800 rounded-lg p-4">
                    <img src="{{ $modalImageData['image'] }}"
                         alt="Proof #{{ $modalImageData['photo']->proof_number }}"
                         class="max-w-full h-auto mx-auto rounded">
                </div>

                <div class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <span class="text-gray-400">File Type:</span>
                        <span class="text-gray-200 ml-2">{{ strtoupper($modalImageData['photo']->file_type) }}</span>
                    </div>
                    @if($modalImageData['photo']->metadata)
                        <div>
                            <span class="text-gray-400">Dimensions:</span>
                            <span class="text-gray-200 ml-2">
                                {{ $modalImageData['photo']->metadata->width }} × {{ $modalImageData['photo']->metadata->height }} px
                            </span>
                        </div>
                        <div>
                            <span class="text-gray-400">File Size:</span>
                            <span class="text-gray-200 ml-2">
                                {{ $this->humanReadableFilesize($modalImageData['photo']->metadata->file_size) }}
                            </span>
                        </div>
                        @if($modalImageData['photo']->metadata->camera_model)
                            <div>
                                <span class="text-gray-400">Camera:</span>
                                <span class="text-gray-200 ml-2">
                                    {{ $modalImageData['photo']->metadata->camera_model }}
                                </span>
                            </div>
                        @endif
                    @endif
                    <div>
                        <span class="text-gray-400">Imported:</span>
                        <span class="text-gray-200 ml-2">
                            {{ $modalImageData['photo']->created_at->format('m/d/Y H:i:s') }}
                        </span>
                    </div>
                    @if($modalImageData['photo']->proofs_generated_at)
                        <div>
                            <span class="text-gray-400">Proofs Generated:</span>
                            <span class="text-gray-200 ml-2">
                                {{ $modalImageData['photo']->proofs_generated_at->format('m/d/Y H:i:s') }}
                            </span>
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </flux:modal>
</div>
