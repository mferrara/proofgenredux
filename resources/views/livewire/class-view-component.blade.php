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
        <div class="ml-2 text-2xl font-semibold">Show: <a href="/show/{{ $show }}" class="text-indigo-600 underline">{{ $show }}</a> / Class: {{ $class }}</a></div>
    </div>

    <div class="mt-4 ml-8 flex flex-col justify-start">
        <div class="flex flex-row justify-between items-start gap-x-4">
            <div class="w-1/2">
                @if(count($current_path_directories) === 0)
                    <div class="text-lg font-semibold">No directories in this path</div>
                @else
                    <p class="mb-2 text-lg font-semibold">Folders in this class folder:</p>

                    @foreach($current_path_directories as $directory)
                        @php
                            $images = $this->getImagesOfPath($directory);
                            $folder_name = explode('/', $directory);
                            $folder_name = end($folder_name);
                            $images_to_process = count($images);
                        @endphp
                        <div class="pl-4 flex flex-row items-center gap-x-2">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5 text-yellow-600">
                                <path d="M3.75 3A1.75 1.75 0 0 0 2 4.75v3.26a3.235 3.235 0 0 1 1.75-.51h12.5c.644 0 1.245.188 1.75.51V6.75A1.75 1.75 0 0 0 16.25 5h-4.836a.25.25 0 0 1-.177-.073L9.823 3.513A1.75 1.75 0 0 0 8.586 3H3.75ZM3.75 9A1.75 1.75 0 0 0 2 10.75v4.5c0 .966.784 1.75 1.75 1.75h12.5A1.75 1.75 0 0 0 18 15.25v-4.5A1.75 1.75 0 0 0 16.25 9H3.75Z" />
                            </svg>
                            <a href="/show/{{ $show }}/class/{{ $class }}/{{ $folder_name }}"
                               class="underline text-indigo-600 hover:cursor-pointer"
                            >{{ $folder_name }}</a>
                            <div class="text-sm">@if(count($images)) {{ count($images) }} images @endif</div>
                        </div>
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

        <div class="mt-8 flex flex-row w-full justify-between items-start gap-x-4">
            <div class="w-1/2">
                <div class="flex flex-row justify-between items-center">
                    <div class="text-xl font-semibold">Images to import</div>
                    <button class="px-2 py-1 text-sm font-semibold bg-gray-200 text-gray-800 rounded-sm border border-gray-300"
                            wire:click="processPendingImages"
                    >Import</button>
                </div>
                <div class="p-2 font-light text-gray-700">Images here have yet to be processed.</div>
                @if(count($images_pending_processing))
                    <div class="mt-6">
                        <div class="flex flex-row items-center gap-x-2">
                            <div class="text-lg font-semibold">Images ({{ count($images_pending_processing) }})</div>
                        </div>

                        <div class="flex flex-col justify-start">
                            @foreach($images_pending_processing as $image)
                                @php
                                    $filename = explode('/', $image->path());
                                    $filename = end($filename);
                                    $modified = $image->lastModified();
                                    $modified = \Carbon\Carbon::createFromTimestamp($modified);
                                @endphp
                                <div class="pl-4 flex flex-row items-center gap-x-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5 text-gray-700">
                                        <path fill-rule="evenodd" d="M1 5.25A2.25 2.25 0 0 1 3.25 3h13.5A2.25 2.25 0 0 1 19 5.25v9.5A2.25 2.25 0 0 1 16.75 17H3.25A2.25 2.25 0 0 1 1 14.75v-9.5Zm1.5 5.81v3.69c0 .414.336.75.75.75h13.5a.75.75 0 0 0 .75-.75v-2.69l-2.22-2.219a.75.75 0 0 0-1.06 0l-1.91 1.909.47.47a.75.75 0 1 1-1.06 1.06L6.53 8.091a.75.75 0 0 0-1.06 0l-2.97 2.97ZM12 7a1 1 0 1 1-2 0 1 1 0 0 1 2 0Z" clip-rule="evenodd" />
                                    </svg>
                                    <div class="text-indigo-600">
                                        {{ $filename }} - {{ $this->humanReadableFilesize($image->filesize()) }} - {{ $modified->toDayDateTimeString() }}
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @else
                    <div class="mt-6">
                        <div class="px-2 py-1 text-lg font-semibold bg-green-200 text-green-800 rounded-sm border border-green-300">No images pending processing</div>
                    </div>
                @endif
            </div>
            <div class="w-1/2">
                <div class="flex flex-row justify-between items-center">
                    <div class="text-xl font-semibold">Images pending proofing</div>
                    <button class="px-2 py-1 text-sm font-semibold bg-gray-200 text-gray-800 rounded-sm border border-gray-300">Start</button>
                </div>
                <div class="p-2 font-light text-gray-700">Images here have been imported (numbered, backed up) but not yet proofed.</div>
                @if(count($images_pending_proofing))
                    <div class="mt-6">
                        <div class="flex flex-row items-center gap-x-2">
                            <div class="text-lg font-semibold">Images ({{ count($images_pending_proofing) }})</div>
                        </div>

                        <div class="flex flex-col justify-start">
                            @foreach($images_pending_proofing as $image)
                                @php
                                    $filename = explode('/', $image->path());
                                    $filename = end($filename);
                                    $modified = $image->lastModified();
                                    $modified = \Carbon\Carbon::createFromTimestamp($modified);
                                @endphp
                                <div class="pl-4 flex flex-row items-center gap-x-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5 text-gray-700">
                                        <path fill-rule="evenodd" d="M1 5.25A2.25 2.25 0 0 1 3.25 3h13.5A2.25 2.25 0 0 1 19 5.25v9.5A2.25 2.25 0 0 1 16.75 17H3.25A2.25 2.25 0 0 1 1 14.75v-9.5Zm1.5 5.81v3.69c0 .414.336.75.75.75h13.5a.75.75 0 0 0 .75-.75v-2.69l-2.22-2.219a.75.75 0 0 0-1.06 0l-1.91 1.909.47.47a.75.75 0 1 1-1.06 1.06L6.53 8.091a.75.75 0 0 0-1.06 0l-2.97 2.97ZM12 7a1 1 0 1 1-2 0 1 1 0 0 1 2 0Z" clip-rule="evenodd" />
                                    </svg>
                                    <div class="text-indigo-600">
                                        {{ $filename }} - {{ $this->humanReadableFilesize($image->filesize()) }} - {{ $modified->toDayDateTimeString() }}
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
