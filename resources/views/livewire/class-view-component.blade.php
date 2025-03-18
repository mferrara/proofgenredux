<div class="px-8 py-4"
     wire:poll.5s
>
    <div class="mt-4 mx-4 flex flex-row justify-between items-center">
        <div class="flex flex-row justify-start items-center">
            <a href="/show/{{ $show }}"
               class="my-2 underline text-indigo-400 hover:cursor-pointer"
               title="Back to {{ $show }}"
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5">
                    <path fill-rule="evenodd" d="M4.72 9.47a.75.75 0 0 0 0 1.06l4.25 4.25a.75.75 0 1 0 1.06-1.06L6.31 10l3.72-3.72a.75.75 0 1 0-1.06-1.06L4.72 9.47Zm9.25-4.25L9.72 9.47a.75.75 0 0 0 0 1.06l4.25 4.25a.75.75 0 1 0 1.06-1.06L11.31 10l3.72-3.72a.75.75 0 0 0-1.06-1.06Z" clip-rule="evenodd" />
                </svg>
            </a>
            <div class="ml-2 text-4xl font-semibold">Show: <a href="/show/{{ $show }}" class="text-indigo-600 underline">{{ $show }}</a> / Class: <span class="text-indigo-300">{{ $class }}</span></a></div>
            <div wire:loading
                 class="ml-4 px-2 py-1 text-sm font-semibold bg-yellow-200 text-yellow-800 rounded-xs border border-yellow-300 animate-pulse"
            >Working...</div>
        </div>
        <div class="flex flex-row justify-start items-center bg-red-50">
            @if(isset($flash_message) && strlen($flash_message))
                <div class="px-2 py-1 text-lg font-semibold bg-green-200 text-green-800 rounded-xs border border-green-300">{{ $flash_message }}</div>
            @endif
        </div>
    </div>

    <div class="mt-4 mx-8 text-xl flex flex-col justify-start">
        <div class="flex flex-row justify-start items-start gap-x-8">
            <div class="w-3/5">
                <div class="grid grid-cols-4 text-right">
                    <div></div><div class="font-semibold">Pending</div><div class="font-semibold">Complete</div><div class="font-semibold">Uploaded</div>
                    <div class="font-semibold">Import</div><div>{{ count($images_pending_processing) }}</div><div>{{ count($images_imported) }}</div><div>n/a</div>
                    <div class="font-semibold">Proof</div><div>{{ count($images_pending_proofing) }}</div><div>{{ count($images_imported) - count($images_pending_proofing) }}</div><div>n/a</div>
                </div>
            </div>
            <div class="w-2/5">
                <div class="bg-gray-600">
                    <div class="text-xl bg-gray-700 text-right px-2">Actions</div>
                    <div class="p-2 flex flex-col gap-y-1">
                        @if($images_pending_processing && count($images_pending_processing))
                            <div class="flex flex-row justify-end gap-x-2">
                                <div class="px-2 py-1 text-sm font-semibold text-gray-300 rounded-xs">Import <span class="text-indigo-300">{!! count($images_pending_processing) !!}</span> {!! str('image')->plural(count($images_pending_processing)) !!}</div>
                                <button class="px-2 py-1 text-sm font-semibold bg-gray-200 text-gray-800 rounded-xs border border-gray-300 hover:bg-gray-300 hover:text-gray-800 hover:border-gray-400"
                                        wire:click="processPendingImages"
                                >Go</button>
                            </div>
                        @endif
                        @if($images_pending_proofing && count($images_pending_proofing))
                            <div class="flex flex-row justify-end gap-x-2">
                                <div class="px-2 py-1 text-sm font-semibold text-gray-300 rounded-xs">Generate proofs and web images on <span class="text-indigo-300">{!! count($images_pending_proofing) !!}</span> {!! str('image')->plural(count($images_pending_proofing)) !!}</div>
                                <button class="px-2 py-1 text-sm font-semibold bg-gray-200 text-gray-800 rounded-xs border border-gray-300"
                                        wire:click="proofPendingImages"
                                >Go</button>
                            </div>
                        @endif
                        <div class="flex flex-row justify-end gap-x-2">
                            <div class="px-2 py-1 text-sm font-semibold text-gray-300 rounded-xs">
                                @if($check_proofs_uploaded === false)
                                    Check proof & web image sync status
                                @elseif($images_pending_upload && count($images_pending_upload))
                                    Upload <span class="text-indigo-300">{!! count($images_pending_upload) !!}</span> proofs and web images
                                @else
                                    <span class="font-semibold text-success">All proofs and web images uploaded</span>
                                @endif
                            </div>
                            @if($check_proofs_uploaded === false)
                                <button class="px-2 py-1 text-sm font-semibold bg-gray-200 text-gray-800 rounded-xs border border-gray-300"
                                        wire:click="checkProofsUploaded"
                                >Go</button>
                            @elseif($images_pending_upload && count($images_pending_upload))
                                <button class="px-2 py-1 text-sm font-semibold bg-gray-200 text-gray-800 rounded-xs border border-gray-300"
                                        wire:click="uploadPendingProofsAndWebImages"
                                >Upload</button>
                            @else
                                <div class="min-w-10"></div>
                            @endif
                        </div>
                        @if($images_imported && count($images_imported))
                            <div class="flex flex-row justify-end gap-x-2 bg-amber-100/20">
                                <div class="px-2 py-1 text-sm font-semibold text-amber-200 rounded-xs">Regenerate proofs on all <span class="text-indigo-300">{!! count($images_imported) !!}</span> imported images</div>
                                <button class="px-2 py-1 text-sm font-semibold bg-gray-200 text-gray-800 rounded-xs border border-gray-300"
                                        wire:click="regenerateProofs"
                                >Go</button>
                            </div>
                        @endif
                        @if(count($images_imported) - count($images_pending_proofing) > 0)
                            <div class="flex flex-row justify-end gap-x-2 bg-amber-100/20">
                                <div class="px-2 py-1 text-sm font-semibold text-amber-200 rounded-xs">Force upload proofs & web images on all <span class="text-indigo-300">{!! count($images_imported) - count($images_pending_proofing) !!}</span> proofed images</div>
                                <button class="px-2 py-1 text-sm font-semibold bg-gray-200 text-gray-800 rounded-xs border border-gray-300"
                                        wire:click="uploadPendingProofsAndWebImages"
                                >Go</button>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
        <ul class="list-disc text-base px-4 mt-8">
            <li><div>Images pending import are any photos in the base
                    <span class="text-indigo-400">/{!! $show !!}/{!! $class !!}</span> folder</div></li>
            <li><div>Images not proofed are any photos in the
                    <span class="text-indigo-400">/{!! $show !!}/{!! $class !!}/originals</span>
                    folder that aren't yet proofed</div></li>
            <li><div>Proofs and Web Images to upload are those that don't yet exist on the web server
                    but have been generated locally</div></li>
        </ul>

        @if(($images_pending_processing && count($images_pending_processing)) || ($images_pending_proofing && count($images_pending_proofing)))
            <div class="mt-8 flex flex-row w-full justify-between items-start gap-x-8 p-3">
                @if($images_pending_processing && count($images_pending_processing))
                    <div class="w-1/2 bg-gray-700">
                        <div class="flex flex-row justify-between items-center">
                            <div class="text-xl py-1 px-2 bg-gray-800 w-full">Images to import @if($images_pending_processing && count($images_pending_processing)) ({{ count($images_pending_processing) }}) @endif</div>
                            @if($images_pending_processing && count($images_pending_processing))

                            @else
                                <div>
                                    <div class="px-2 py-1 text-sm font-semibold text-success rounded-xs">All images imported</div>
                                </div>
                            @endif
                        </div>
                        @if($images_pending_processing && count($images_pending_processing))
                            <div class="mt-6 px-2 mb-2">
                                @include('components.partials.images-table', ['images' => $images_pending_processing, 'actions' => ['import']])
                            </div>
                        @endif
                    </div>
                @endif
                @if($images_pending_proofing && count($images_pending_proofing))
                    <div class="w-1/2 bg-gray-700">
                        <div class="flex flex-row justify-between items-center">
                            <div class="text-xl py-1 px-2 bg-gray-800 w-full">Images to proof @if($images_pending_proofing && count($images_pending_proofing))({{ count($images_pending_proofing)}})@endif </div>
                        </div>
                        @if($images_pending_proofing && count($images_pending_proofing))
                            <div class="mt-6 px-2 mb-2">
                                @include('components.partials.images-table', ['images' => $images_pending_proofing, 'actions' => ['proof']])
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        @endif

        @if(($images_pending_upload && count($images_pending_upload)) || ($web_images_pending_upload && count($web_images_pending_upload)))
            <div class="mt-8 flex flex-row w-full justify-between items-start gap-x-8 border border-gray-200 p-3">
                <div class="w-1/2">
                    <div class="flex flex-row justify-between items-center">
                        <div class="text-xl font-semibold">Proofs to upload @if($images_pending_upload && count($images_pending_upload))({{ count($images_pending_upload)}})@endif </div>
                        <div class="flex flex-row justify-end items-center gap-x-2">
                            <button class="px-2 py-1 text-sm font-semibold bg-gray-200 text-gray-800 rounded-xs border border-gray-300"
                                    wire:click="uploadPendingProofsAndWebImages"
                            >Force Upload</button>
                        </div>
                    </div>
                    @if($images_pending_upload && count($images_pending_upload))
                        <div class="mt-6">
                            @include('components.partials.images-table', ['images' => $images_pending_upload, 'actions' => []])
                        </div>
                    @endif
                </div>
                <div class="w-1/2">
                    <div class="flex flex-row justify-between items-center">
                        <div class="text-xl font-semibold">Web Images to upload @if($web_images_pending_upload && count($web_images_pending_upload))({{ count($web_images_pending_upload)}})@endif </div>
                    </div>
                    @if($web_images_pending_upload && count($web_images_pending_upload))
                        <div class="mt-6">
                            @include('components.partials.images-table', ['images' => $web_images_pending_upload, 'actions' => []])
                        </div>
                    @endif
                </div>
            </div>
        @endif

        <div class="mt-8 flex flex-row w-full justify-between items-start gap-x-8">
            <div class="w-3/4 mx-auto">
                <div class="flex flex-row justify-between items-center">
                    <div class="text-xl font-semibold">Class photos @if($images_imported && count($images_imported)) ({{ count($images_imported) }}) @endif</div>
                    @if($images_imported && count($images_imported))

                    @else
                        <div>
                            <div class="px-2 py-1 text-sm font-semibold bg-green-200 text-green-800 rounded-xs border border-green-300">No images imported yet</div>
                        </div>
                    @endif
                </div>
                <div class="p-2 text-lg font-light text-gray-700">Images here have been processed.</div>
                @if($images_imported && count($images_imported))
                    <div class="mt-6">
                        @include('components.partials.images-table', ['images' => $images_imported, 'actions' => [], 'display_thumbnail' => true, 'details' => true])
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
