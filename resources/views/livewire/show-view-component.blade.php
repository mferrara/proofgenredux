<div class="bg-gray-100 px-8 py-4">
    <div class="mt-4 flex flex-row justify-start items-center">
        <a href="/"
           class="mt-2 underline text-indigo-400 hover:cursor-pointer mb-2 ml-2"
           title="Back to Home"
        >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5">
                <path fill-rule="evenodd" d="M4.72 9.47a.75.75 0 0 0 0 1.06l4.25 4.25a.75.75 0 1 0 1.06-1.06L6.31 10l3.72-3.72a.75.75 0 1 0-1.06-1.06L4.72 9.47Zm9.25-4.25L9.72 9.47a.75.75 0 0 0 0 1.06l4.25 4.25a.75.75 0 1 0 1.06-1.06L11.31 10l3.72-3.72a.75.75 0 0 0-1.06-1.06Z" clip-rule="evenodd" />
            </svg>
        </a>
        <div class="ml-2 text-4xl font-semibold">Show: <a href="/show/{{ $show }}" class="text-indigo-600">{{ $show }}</a></div>
    </div>
    <div class="mt-4 ml-12 text-xl flex flex-col justify-start gap-y-0.5">
        @if(count($current_path_directories) === 0)
            <div class="font-semibold">No directories in this path</div>
        @else
            <div class="flex flex-row justify-between items-center">
                <p class="mb-2 font-semibold">Classes</p>
                <div class="flex flex-row justify-end items-center gap-x-2">
                    <div wire:loading
                         class="px-2 py-1 text-sm bg-yellow-200 text-yellow-800 rounded-sm border border-yellow-300 animate-pulse"
                    >Working...</div>
                    @if(isset($flash_message) && strlen($flash_message))
                        <div class="px-2 py-1 text-sm font-semibold bg-green-200 text-green-800 rounded-sm border border-green-300">{{ $flash_message }}</div>
                    @endif
                    @if( ! $check_proofs_uploaded)
                        <button class="px-2 py-1 text-sm font-semibold bg-gray-200 text-gray-800 rounded-sm border border-gray-300"
                                wire:click="checkProofsUploaded"
                        >Check Proofs</button>
                    @else
                        @if(count($images_pending_upload) || count($web_images_pending_upload))
                            <div class="flex flex-row justify-end items-center gap-x-2">
                                <div class="px-2 py-1 text-sm font-semibold bg-yellow-200 text-yellow-800 rounded-sm border border-yellow-300">Proofs to upload: {{ count($images_pending_upload) }}</div>
                                <div class="px-2 py-1 text-sm font-semibold bg-yellow-200 text-yellow-800 rounded-sm border border-yellow-300">Web Images to upload: {{ count($web_images_pending_upload) }}</div>
                                <button class="px-2 py-1 text-sm font-semibold bg-red-200 text-green-800 rounded-sm border border-green-300"
                                        wire:click="uploadPendingProofs"
                                >Upload</button>
                            </div>
                        @else
                            <div>
                                <div class="px-2 py-1 text-sm font-semibold bg-green-200 text-green-800 rounded-sm border border-green-300">All proofs uploaded</div>
                            </div>
                        @endif
                    @endif
                </div>
            </div>

            <table class="w-2/3">
                <thead>
                <tr class="border-b border-b-indigo-300">
                    <th></th>
                    <th class="text-right">Photos</th>
                    <th class="text-right">To Import</th>
                    <th class="text-right">To Proof</th>
                    <th class="text-right">Actions</th>
                </tr>
                </thead>
                <tbody>
                @foreach($class_folders as $key => $class_folder_data)
                    <tr class="@if($key % 2 === 0) bg-indigo-100 @endif">
                        <td class="flex flex-row justify-start gap-x-1 items-center">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5 text-yellow-600">
                                <path d="M3.75 3A1.75 1.75 0 0 0 2 4.75v3.26a3.235 3.235 0 0 1 1.75-.51h12.5c.644 0 1.245.188 1.75.51V6.75A1.75 1.75 0 0 0 16.25 5h-4.836a.25.25 0 0 1-.177-.073L9.823 3.513A1.75 1.75 0 0 0 8.586 3H3.75ZM3.75 9A1.75 1.75 0 0 0 2 10.75v4.5c0 .966.784 1.75 1.75 1.75h12.5A1.75 1.75 0 0 0 18 15.25v-4.5A1.75 1.75 0 0 0 16.25 9H3.75Z" />
                            </svg>
                            <a href="/show/{{ $show }}/class/{{ $class_folder_data['path'] }}"
                               class="underline text-indigo-600 hover:cursor-pointer"
                            >{{ $class_folder_data['path'] }}</a>
                        </td>
                        <td class="text-right">@if($class_folder_data['images_imported']){{ $class_folder_data['images_imported'] }}@endif</td>
                        <td class="text-right">@if($class_folder_data['images_pending_processing_count']){{ $class_folder_data['images_pending_processing_count'] }}@endif</td>
                        <td class="text-right">@if($class_folder_data['images_pending_proofing_count']){{ $class_folder_data['images_pending_proofing_count'] }}@endif</td>
                        <td class="text-right"></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
