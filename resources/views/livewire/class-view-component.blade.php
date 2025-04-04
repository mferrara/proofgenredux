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
            <div class="col-span-5">
                <flux:card>
                    <flux:heading size="lg" class="mb-4">Photo Processing Status</flux:heading>

                    <flux:table class="!text-gray-400">
                        <thead>
                            <tr>
                                <th></th>
                                <th class="text-center">Pending</th>
                                <th class="text-center">Complete</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="h-8 font-medium">Imported</td>
                                <td class="text-center">
                                    @if(count($photos_pending_import))
                                        <flux:badge color="sky">
                                            {{ count($photos_pending_import) }}
                                        </flux:badge>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if($photos_imported->count())
                                        <flux:badge color="green">
                                            {{ $photos_imported->count() }}
                                        </flux:badge>
                                    @else
                                        -
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <td class="h-8 font-medium">Proofed</td>
                                <td class="text-center">
                                    @if($photos_pending_proofs->count())
                                        <flux:badge color="sky">
                                            {{ $photos_pending_proofs->count() }}
                                        </flux:badge>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if($photos_proofed->count())
                                        <flux:badge color="green">
                                            {{ $photos_proofed->count() }}
                                        </flux:badge>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <td class="h-8 font-medium">&nbsp;&nbsp;- Uploaded</td>
                                <td class="text-center">
                                    @if($photos_pending_proof_uploads->count())
                                        <flux:badge color="sky">
                                            {{ $photos_pending_proof_uploads->count() }}
                                        </flux:badge>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if($photos_proofs_uploaded->count())
                                        <flux:badge color="green">
                                            {{ $photos_proofs_uploaded->count() }}
                                        </flux:badge>
                                    @else
                                        -
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <td class="h-8 font-medium">Web Images</td>
                                <td class="text-center">
                                    @if($photos_pending_web_images->count())
                                        <flux:badge color="sky">
                                            {{ $photos_pending_web_images->count() }}
                                        </flux:badge>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if($photos_web_images_generated->count())
                                        <flux:badge color="green">
                                            {{ $photos_web_images_generated->count() }}
                                        </flux:badge>
                                    @else
                                        -
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <td class="h-8 font-medium">&nbsp;&nbsp;- Uploaded</td>
                                <td class="text-center">
                                    @if($photos_pending_web_image_uploads->count())
                                        <flux:badge color="sky">
                                            {{ $photos_pending_web_image_uploads->count() }}
                                        </flux:badge>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if($photos_web_images_uploaded->count())
                                        <flux:badge color="green">
                                            {{ $photos_web_images_uploaded->count() }}
                                        </flux:badge>
                                    @else
                                        -
                                    @endif
                                </td>
                            </tr>
                        </tbody>
                    </flux:table>
                </flux:card>
            </div>

            <!-- Action Panel -->
            <div class="col-span-7">
                <flux:card>

                    <div class="grid grid-cols-3 gap-6 mb-8">
                        <div>
                            <flux:heading size="xl" class="mb-4">Imports</flux:heading>

                            <div class="flex flex-col gap-3 text-gray-400">
                                @if($photos_pending_import && count($photos_pending_import))
                                    <div class="flex flex-col justify-start gap-y-4">
                                        <div class="p-4 bg-gray-500/10 rounded-md">
                                            <flux:badge color="sky" size="lg" inset="top bottom">{{ count($photos_pending_import) }}</flux:badge> pending
                                        </div>
                                        <div>
                                            <flux:button
                                                wire:click="importPendingImages"
                                                size="sm"
                                            >
                                                Import
                                            </flux:button>
                                        </div>
                                    </div>
                                @else
                                    <div class="p-4 bg-gray-500/10 rounded-md"><flux:badge color="gray" size="lg" inset="top bottom">0</flux:badge> pending</div>
                                @endif
                            </div>
                        </div>
                        <div>
                            <flux:heading size="xl" class="mb-4">Proofs</flux:heading>

                            <div class="flex flex-col gap-3 text-gray-400">

                                @if($photos_pending_proofs->count())
                                    <div class="flex flex-col justify-start gap-y-4">
                                        <div class="p-4 bg-gray-500/10 rounded-md">
                                            <flux:badge color="sky" size="lg" inset="top bottom">{{ $photos_pending_proofs->count() }}</flux:badge> pending
                                        </div>
                                        <div>
                                            <flux:button
                                                wire:click="proofPendingPhotos"
                                                size="sm"
                                            >
                                                Generate
                                            </flux:button>
                                        </div>
                                    </div>
                                @else
                                    <div class="p-4 bg-gray-500/10 rounded-md"><flux:badge color="gray" size="lg" inset="top bottom">0</flux:badge> pending</div>
                                @endif
                            </div>
                        </div>
                        <div>
                            <flux:heading size="xl" class="mb-4">Web Images</flux:heading>

                            <div class="flex flex-col gap-3 text-gray-400">

                                @if($photos_pending_web_images->count())
                                    <div class="flex flex-col justify-start gap-y-4">
                                        <div class="p-4 bg-gray-500/10 rounded-md">
                                            <flux:badge color="sky" size="lg" inset="top bottom">{{ $photos_pending_web_images->count() }}</flux:badge> pending
                                        </div>
                                        <div><flux:button
                                                wire:click="webImagePendingPhotos"
                                                size="sm"
                                            >
                                                Generate
                                            </flux:button>
                                        </div>
                                    </div>
                                @else
                                    <div class="p-4 bg-gray-500/10 rounded-md"><flux:badge color="gray" size="lg" inset="top bottom">0</flux:badge> pending</div>
                                @endif
                            </div>
                        </div>
                    </div>

                    <flux:heading size="xl" class="my-4">Uploads</flux:heading>

                    <div class="flex flex-col gap-3 text-gray-400">

                        @if($photos_pending_proof_uploads->count() || $photos_pending_web_image_uploads->count())
                            <div class="flex justify-between items-center">
                                <span>
                                    @if($photos_pending_proof_uploads->count())
                                        <flux:badge color="amber" size="lg" inset="top bottom">{{ $photos_pending_proof_uploads->count() }}</flux:badge> Proofs
                                    @endif
                                    @if($photos_pending_web_image_uploads->count())
                                        @if($photos_pending_proof_uploads->count())
                                            and
                                        @endif
                                        <flux:badge color="amber" size="lg" inset="top bottom">{{ $photos_pending_web_image_uploads->count() }}</flux:badge> Web images
                                    @endif
                                    pending upload
                                </span>

                                <flux:button
                                    wire:click="uploadPendingProofsAndWebImages"
                                    size="sm"
                                >
                                    Upload
                                </flux:button>
                            </div>
                        @else
                            <div class="flex justify-between items-center ml-2 min-h-12 px-2 py-1 rounded-sm bg-green-400/40 text-green-200 font-semibold">
                                <div class="">
                                    All proofs and web images uploaded
                                </div>
                            </div>
                        @endif

                            <div class="flex justify-between items-center ml-2 min-h-12 pl-2 py-1">
                                <div x-data="{ expanded: false }"
                                    class="flex flex-col gap-y-1">
                                    <div class="flex items-center gap-x-2">
                                        <div>Web Server Status Check</div>
                                        <div x-on:click="expanded = ! expanded"
                                            class="text-gray-500 text-sm underline hover:cursor-pointer">
                                            <span x-show="! expanded">(more info)</span>
                                            <span x-show="expanded" x-cloak>(hide info)</span>
                                        </div>
                                    </div>
                                    <div x-show="expanded"
                                         x-cloak
                                        class="pl-2 pr-6 text-sm text-gray-500">
                                        Performs a "dry run" of the uploads to the web server and
                                        updates the status of the files in the database. Does not upload anything. Fast.
                                    </div>
                                </div>

                                <flux:button
                                    wire:click="checkProofAndWebImageUploads"
                                    size="sm"
                                >
                                    Check
                                </flux:button>
                            </div>

                        @if($photos_imported->count())
                            <div class="flex justify-between items-center border-t border-gray-700 pt-3 mt-2">
                                <span>Regenerate proofs on <flux:badge color="amber" size="sm" inset="top bottom">{{ $photos_imported->count() }}</flux:badge> photos</span>
                                <flux:button
                                    wire:click="regenerateProofs"
                                    size="sm"
                                >
                                    Regenerate
                                </flux:button>
                            </div>
                        @endif

                        @if($photos_imported->count() - $photos_pending_proofs->count() > 0)
                            <div class="flex justify-between items-center">
                                <span>Upload all <flux:badge color="amber" size="sm" inset="top bottom">{{ $photos_imported->count() - $photos_pending_proofs->count() }}</flux:badge> images</span>
                                <flux:button
                                    wire:click="uploadPendingProofsAndWebImages"
                                    size="sm"
                                >
                                    Upload All
                                </flux:button>
                            </div>
                        @endif
                    </div>
                </flux:card>
            </div>
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
                                class="hover:text-indigo-500 hover:cursor-pointer hover:bg-gray-50/10 rounded-sm p-1"
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
                            <div class="hover:text-indigo-500 hover:cursor-pointer hover:bg-gray-50/10 rounded-sm p-1"
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
                                class="hover:text-indigo-500 hover:cursor-pointer hover:bg-gray-50/10 rounded-sm p-1"
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
                            <div class="hover:text-indigo-500 hover:cursor-pointer hover:bg-gray-50/10 rounded-sm p-1"
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
                                class="hover:text-indigo-500 hover:cursor-pointer hover:bg-gray-50/10 rounded-sm p-1"
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
                            <div class="hover:text-indigo-500 hover:cursor-pointer hover:bg-gray-50/10 rounded-sm p-1"
                                 wire:click="openFolder('{{ $show_class->full_proofs_path }}')">
                                <flux:icon.arrow-top-right-on-square class="size-5" />
                            </div>
                        </flux:tooltip>
                    </div>
                </li>
                <li>
                    <div class="flex flex-row items-center gap-x-1">
                        <div class="flex flex-row items-center gap-x-1">
                            <div><flux:badge color="indigo">Web Images</flux:badge> generated for this classes imported photos are located in the</div>
                            <span class="text-indigo-400 px-2 py-1 bg-gray-400/10 shadow-md">{{ $show_class->full_web_images_path }}</span>
                            <div>directory</div>
                        </div>
                        <flux:tooltip content="Copy path to clipboard">
                            <div
                                class="hover:text-indigo-500 hover:cursor-pointer hover:bg-gray-50/10 rounded-sm p-1"
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
                            <div class="hover:text-indigo-500 hover:cursor-pointer hover:bg-gray-50/10 rounded-sm p-1"
                                 wire:click="openFolder('{{ $show_class->full_web_images_path }}')">
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
