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
            <div class="col-span-6">
                <flux:card>
                    <flux:heading size="lg" class="mb-4">Image Status</flux:heading>

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
                                <td class="font-medium">Imported</td>
                                <td class="text-center">
                                    @if(count($images_pending_processing))
                                        <flux:badge variant="outline" color="sky" size="sm">
                                            {{ count($images_pending_processing) }}
                                        </flux:badge>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="text-center">{{ count($images_imported) }}</td>
                            </tr>
                            <tr>
                                <td class="font-medium">Proofed</td>
                                <td class="text-center">
                                    @if(count($images_pending_proofing))
                                        <flux:badge variant="outline" color="sky" size="sm">
                                            {{ count($images_pending_proofing) }}
                                        </flux:badge>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="text-center">{{ count($images_imported) - count($images_pending_proofing) }}</td>
                            </tr>
                            <tr>
                                <td class="text-medium">Uploaded</td>
                                <td class="text-center">
                                    @if(count($images_pending_upload))
                                        <flux:badge variant="outline" color="sky" size="sm">
                                            {{ count($images_pending_upload) }}
                                        </flux:badge>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if(isset($proofs_uploaded) && count($proofs_uploaded))
                                        <flux:badge variant="outline" color="sky" size="sm">
                                            {{ count($proofs_uploaded) }}
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
            <div class="col-span-6">
                <flux:card>
                    <flux:heading size="lg" class="mb-4">Actions</flux:heading>

                    <div class="flex flex-col gap-3 text-gray-400">
                        @if($images_pending_processing && count($images_pending_processing))
                            <div class="flex justify-between items-center">
                                <span>Import <flux:badge variant="outline" color="sky" size="sm">{{ count($images_pending_processing) }}</flux:badge> {{ str('image')->plural(count($images_pending_processing)) }}</span>
                                <flux:button
                                    wire:click="processPendingImages"
                                    size="sm"
                                >
                                    Process
                                </flux:button>
                            </div>
                        @endif

                        @if($images_pending_proofing && count($images_pending_proofing))
                            <div class="flex justify-between items-center">
                                <span>Generate proofs for <flux:badge variant="outline" color="sky" size="sm">{{ count($images_pending_proofing) }}</flux:badge> {{ str('image')->plural(count($images_pending_proofing)) }}</span>
                                <flux:button
                                    wire:click="proofPendingImages"
                                    size="sm"
                                >
                                    Generate
                                </flux:button>
                            </div>
                        @endif

                        <div class="flex justify-between items-center">
                            @if($check_proofs_uploaded === false)
                                <span>Check proof & web image sync status</span>
                                <flux:button
                                    wire:click="checkProofsUploaded"
                                    size="sm"
                                >
                                    Check
                                </flux:button>
                            @elseif($images_pending_upload && count($images_pending_upload))
                                <span>Upload <flux:badge variant="solid" color="blue" size="sm">{{ count($images_pending_upload) }}</flux:badge> proofs and web images</span>
                                <flux:button
                                    wire:click="uploadPendingProofsAndWebImages"
                                    size="sm"
                                >
                                    Upload
                                </flux:button>
                            @else
                                <flux:badge variant="solid" color="green" size="sm">All proofs and web images uploaded</flux:badge>
                            @endif
                        </div>

                        @if($images_imported && count($images_imported))
                            <div class="flex justify-between items-center border-t border-gray-700 pt-3 mt-2">
                                <span>Regenerate proofs on <flux:badge variant="outline" color="amber" size="sm">{{ count($images_imported) }}</flux:badge> images</span>
                                <flux:button
                                    wire:click="regenerateProofs"
                                    size="sm"
                                >
                                    Regenerate
                                </flux:button>
                            </div>
                        @endif

                        @if(count($images_imported) - count($images_pending_proofing) > 0)
                            <div class="flex justify-between items-center">
                                <span>Upload all <flux:badge variant="outline" color="amber" size="sm">{{ count($images_imported) - count($images_pending_proofing) }}</flux:badge> images</span>
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
            <flux:heading size="lg" class="mb-4">Information</flux:heading>
            <ul class="list-disc pl-5 space-y-2 text-gray-400">
                <li>Images pending import are any photos in the base
                    <span class="text-indigo-400 font-medium">/{!! $show !!}/{!! $class !!}</span> folder</li>
                <li>Images not proofed are any photos in the
                    <span class="text-indigo-400 font-medium">/{!! $show !!}/{!! $class !!}/originals</span>
                    folder that aren't yet proofed</li>
                <li>Proofs and Web Images to upload are those that don't yet exist on the web server
                    but have been generated locally</li>
            </ul>
        </flux:card>

        @if(($images_pending_processing && count($images_pending_processing)) || ($images_pending_proofing && count($images_pending_proofing)))
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                @if($images_pending_processing && count($images_pending_processing))
                    <flux:card>
                        <flux:heading size="lg" class="mb-4">
                            Images to Import ({{ count($images_pending_processing) }})
                        </flux:heading>

                        @include('components.partials.images-table', ['images' => $images_pending_processing, 'actions' => ['import']])
                    </flux:card>
                @endif

                @if($images_pending_proofing && count($images_pending_proofing))
                    <flux:card>
                        <flux:heading size="lg" class="mb-4">
                            Images to Proof ({{ count($images_pending_proofing) }})
                        </flux:heading>

                        @include('components.partials.images-table', ['images' => $images_pending_proofing, 'actions' => ['proof']])
                    </flux:card>
                @endif
            </div>
        @endif

        @if(($images_pending_upload && count($images_pending_upload)) || ($web_images_pending_upload && count($web_images_pending_upload)))
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                @if($images_pending_upload && count($images_pending_upload))
                    <flux:card>
                        <div class="flex justify-between items-center mb-4">
                            <flux:heading>
                                Proofs to Upload ({{ count($images_pending_upload) }})
                            </flux:heading>

                            <flux:button
                                wire:click="uploadPendingProofsAndWebImages"
                                size="xs"
                            >
                                Force Upload
                            </flux:button>
                        </div>

                        @include('components.partials.images-table', ['images' => $images_pending_upload, 'actions' => []])
                    </flux:card>
                @endif

                @if($web_images_pending_upload && count($web_images_pending_upload))
                    <flux:card>
                        <flux:heading class="mb-4">
                            Web Images to Upload ({{ count($web_images_pending_upload) }})
                        </flux:heading>

                        @include('components.partials.images-table', ['images' => $web_images_pending_upload, 'actions' => []])
                    </flux:card>
                @endif
            </div>
        @endif

        <flux:card>
            <div class="flex justify-between items-center mb-4">
                <flux:heading size="xl">
                    Processed Photos {{ $images_imported && count($images_imported) ? '(' . count($images_imported) . ')' : '' }}
                </flux:heading>

                @if(!$images_imported || !count($images_imported))
                    <flux:badge variant="solid" color="amber" size="sm">No images imported yet</flux:badge>
                @endif
            </div>

            <p class="text-gray-400 mb-6">Images here have been processed.</p>

            @if($images_imported && count($images_imported))
                @include('components.partials.photos-table', ['photos' => $photos, 'actions' => [], 'display_thumbnail' => true, 'details' => true])
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
