<div class="bg-gray-100 px-8 py-4">
    <div class="mt-4 flex flex-row justify-start items-center">
        <a href="/show/{{ $show }}"
           class="mt-2 underline text-indigo-400 hover:cursor-pointer mb-2 ml-2"
           title="Back to {{ $show }}"
        >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5">
                <path fill-rule="evenodd" d="M4.72 9.47a.75.75 0 0 0 0 1.06l4.25 4.25a.75.75 0 1 0 1.06-1.06L6.31 10l3.72-3.72a.75.75 0 1 0-1.06-1.06L4.72 9.47Zm9.25-4.25L9.72 9.47a.75.75 0 0 0 0 1.06l4.25 4.25a.75.75 0 1 0 1.06-1.06L11.31 10l3.72-3.72a.75.75 0 0 0-1.06-1.06Z" clip-rule="evenodd" />
            </svg>
        </a>
        <div class="ml-2 text-4xl font-semibold">Show: <a href="/show/{{ $show }}" class="text-indigo-600 underline">{{ $show }}</a> / Class: {{ $class }}</a></div>
    </div>

    <div class="mt-4 ml-12 text-xl flex flex-col justify-start">
        <div class="flex flex-row justify-between items-start gap-x-8">
            <div class="w-1/2">
                @if(count($current_path_directories) === 0)
                    <div class="font-semibold">No directories in this class</div>
                    <div class="p-2 text-lg font-light text-gray-700">This indicates that we've yet to import any images to this class.</div>
                @else
                    <p class="mb-2 font-semibold">Folders in this class folder:</p>

                    @foreach($current_path_directories as $directory)
                        @php
                            $images = $this->getImagesOfPath($directory);
                            $folder_name = explode('/', $directory);
                            $folder_name = end($folder_name);
                            $images_to_process = count($images);
                            $subdirectories = \App\Proofgen\Utility::getDirectoriesOfPath($directory);
                        @endphp
                        <div class="pl-4 flex flex-row items-center gap-x-2">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5 text-yellow-600">
                                <path d="M3.75 3A1.75 1.75 0 0 0 2 4.75v3.26a3.235 3.235 0 0 1 1.75-.51h12.5c.644 0 1.245.188 1.75.51V6.75A1.75 1.75 0 0 0 16.25 5h-4.836a.25.25 0 0 1-.177-.073L9.823 3.513A1.75 1.75 0 0 0 8.586 3H3.75ZM3.75 9A1.75 1.75 0 0 0 2 10.75v4.5c0 .966.784 1.75 1.75 1.75h12.5A1.75 1.75 0 0 0 18 15.25v-4.5A1.75 1.75 0 0 0 16.25 9H3.75Z" />
                            </svg>
                            <a href="/show/{{ $show }}/class/{{ $class }}/{{ $folder_name }}"
                               class="underline text-indigo-600 hover:cursor-pointer"
                            >{{ $folder_name }}</a>
                            <div class="">@if(count($images)) {{ count($images) }} images @endif</div>
                        </div>
                        @if($subdirectories && count($subdirectories))
                            <div class="pl-8 flex flex-row items-center gap-x-2">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5 text-yellow-600">
                                    <path d="M3.75 3A1.75 1.75 0 0 0 2 4.75v3.26a3.235 3.235 0 0 1 1.75-.51h12.5c.644 0 1.245.188 1.75.51V6.75A1.75 1.75 0 0 0 16.25 5h-4.836a.25.25 0 0 1-.177-.073L9.823 3.513A1.75 1.75 0 0 0 8.586 3H3.75ZM3.75 9A1.75 1.75 0 0 0 2 10.75v4.5c0 .966.784 1.75 1.75 1.75h12.5A1.75 1.75 0 0 0 18 15.25v-4.5A1.75 1.75 0 0 0 16.25 9H3.75Z" />
                                </svg>
                                @foreach($subdirectories as $subdirectory)
                                    @php
                                        $images = $this->getImagesOfPath($subdirectory);
                                        $sub_folder_name = explode('/', $subdirectory);
                                        $sub_folder_name = end($sub_folder_name);
                                    @endphp
                                    <div class="flex flex-row items-center gap-x-2">
                                        <a href="/show/{{ $show }}/class/{{ $class }}/{{ $sub_folder_name }}"
                                           class="underline text-indigo-600 hover:cursor-pointer"
                                        >{{ $sub_folder_name }}</a>
                                        <div class="">@if(count($images)) {{ count($images) }} images @endif</div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    @endforeach
                @endif
            </div>
            <div class="w-1/2">
                <div wire:loading
                    class="w-1/3 px-2 py-1 text-lg font-semibold bg-yellow-200 text-yellow-800 rounded-sm border border-yellow-300 animate-pulse"
                >Working...</div>
                @if(isset($flash_message) && strlen($flash_message))
                    <div class="px-2 py-1 text-lg font-semibold bg-green-200 text-green-800 rounded-sm border border-green-300">{{ $flash_message }}</div>
                @endif
            </div>
        </div>

        <div class="mt-8 flex flex-row w-full justify-between items-start gap-x-8">
            <div class="w-1/2">
                <div class="flex flex-row justify-between items-center">
                    <div class="text-xl font-semibold">Images to import @if($images_pending_processing && count($images_pending_processing)) ({{ count($images_pending_processing) }}) @endif</div>
                    @if($images_pending_processing && count($images_pending_processing))
                        <button class="px-2 py-1 text-sm font-semibold bg-gray-200 text-gray-800 rounded-sm border border-gray-300 hover:bg-gray-300 hover:text-gray-800 hover:border-gray-400"
                                wire:click="processPendingImages"
                        >Import</button>
                    @else
                        <div>
                            <div class="px-2 py-1 text-sm font-semibold bg-green-200 text-green-800 rounded-sm border border-green-300">All images imported</div>
                        </div>
                    @endif
                </div>
                <div class="p-2 text-lg font-light text-gray-700">Images here have yet to be processed.</div>
                @if($images_pending_processing && count($images_pending_processing))
                    <div class="mt-6">
                        @include('components.partials.images-table', ['images' => $images_pending_processing, 'actions' => ['import']])
                    </div>
                @endif
            </div>
            <div class="w-1/2">
                <div class="flex flex-row justify-between items-center">
                    <div class="text-xl font-semibold">Images to proof @if($images_pending_proofing && count($images_pending_proofing))({{ count($images_pending_proofing)}})@endif </div>
                    @if($images_pending_proofing && count($images_pending_proofing))
                        <button class="px-2 py-1 text-sm font-semibold bg-gray-200 text-gray-800 rounded-sm border border-gray-300"
                                wire:click="proofPendingImages"
                        >Start</button>
                    @else
                        <div>
                            <div class="px-2 py-1 text-sm font-semibold bg-green-200 text-green-800 rounded-sm border border-green-300">All images proofed</div>
                        </div>
                    @endif
                </div>
                <div class="p-2 text-lg font-light text-gray-700">Images processed (numbered, backed up) but not yet proofed.</div>
                @if($images_pending_proofing && count($images_pending_proofing))
                    <div class="mt-6">
                        @include('components.partials.images-table', ['images' => $images_pending_proofing, 'actions' => ['proof']])
                    </div>
                @endif
            </div>
        </div>

        <div class="mt-8 flex flex-row w-full justify-between items-start gap-x-8">
            <div class="w-1/2">
                <div class="flex flex-row justify-between items-center">
                    <div class="text-xl font-semibold">Proofs to upload @if($images_pending_upload && count($images_pending_upload))({{ count($images_pending_upload)}})@endif </div>
                    @if($check_proofs_uploaded === false)
                        <button class="px-2 py-1 text-sm font-semibold bg-gray-200 text-gray-800 rounded-sm border border-gray-300"
                                wire:click="checkProofsUploaded"
                        >Check</button>
                    @elseif($images_pending_upload && count($images_pending_upload))
                        <button class="px-2 py-1 text-sm font-semibold bg-gray-200 text-gray-800 rounded-sm border border-gray-300"
                                wire:click="uploadPendingProofsAndWebImages"
                        >Upload</button>
                    @else
                        <div>
                            <div class="px-2 py-1 text-sm font-semibold bg-green-200 text-green-800 rounded-sm border border-green-300">All proofs uploaded</div>
                        </div>
                    @endif
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
                    @if($check_proofs_uploaded === false)
                        <button class="px-2 py-1 text-sm font-semibold bg-gray-200 text-gray-800 rounded-sm border border-gray-300"
                                wire:click="checkProofsUploaded"
                        >Check</button>
                    @elseif($images_pending_upload && count($images_pending_upload))
                        <button class="px-2 py-1 text-sm font-semibold bg-gray-200 text-gray-800 rounded-sm border border-gray-300"
                                wire:click="uploadPendingProofsAndWebImages"
                        >Upload</button>
                    @else
                        <div>
                            <div class="px-2 py-1 text-sm font-semibold bg-green-200 text-green-800 rounded-sm border border-green-300">All proofs uploaded</div>
                        </div>
                    @endif
                </div>
                @if($web_images_pending_upload && count($web_images_pending_upload))
                    <div class="mt-6">
                        @include('components.partials.images-table', ['images' => $web_images_pending_upload, 'actions' => []])
                    </div>
                @endif
            </div>
        </div>

        <div class="mt-8 flex flex-row w-full justify-between items-start gap-x-8">
            <div class="w-3/4 mx-auto">
                <div class="flex flex-row justify-between items-center">
                    <div class="text-xl font-semibold">Class photos @if($images_imported && count($images_imported)) ({{ count($images_imported) }}) @endif</div>
                    @if($images_imported && count($images_imported))

                    @else
                        <div>
                            <div class="px-2 py-1 text-sm font-semibold bg-green-200 text-green-800 rounded-sm border border-green-300">No images imported yet</div>
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
