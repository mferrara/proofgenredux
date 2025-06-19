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
                            <div><flux:badge color="teal">Imported Photos</flux:badge> are moved from the base class directory (above) into the</div>
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
                <div class="flex flex-row justify-end items-center gap-x-6">
                    @if($show_delete)
                        <flux:badge variant="solid" color="red" size="lg" class="animate-pulse">
                            Delete mode enabled
                        </flux:badge>
                    @endif
                    <flux:switch
                        wire:model.live="show_delete"
                        label="Show delete"
                        class="!text-gray-400"></flux:switch>
                </div>
                @include('components.partials.photos-table', ['photos' => $photos, 'actions' => ['deletePhotoRecord', 'deleteLocalProofs'], 'display_thumbnail' => true, 'details' => true])
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
</div>
