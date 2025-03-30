<div class="px-8 py-6" wire:poll.5s>
    <div class="max-w-6xl mx-auto">
        <div class="flex items-center gap-3 mb-6">
            <a href="/" class="text-indigo-400 hover:text-indigo-300" title="Back to Home">
                <flux:icon name="chevron-left" variant="outline" />
            </a>
            <flux:heading size="xl">
                Show: <a href="/show/{{ $show_id }}" class="text-indigo-500 hover:text-indigo-400">{{ $show_id }}</a>
            </flux:heading>

            <div wire:loading class="ml-3">
                <flux:badge variant="solid" color="sky" size="sm" class="animate-pulse">
                    Working...
                </flux:badge>
            </div>
        </div>

        <div class="mt-4 flex flex-col gap-4">
            @if(count($current_path_directories) === 0)
                <div class="py-8 text-center bg-gray-800/50 rounded-md">
                    <div class="mb-4">
                        <flux:icon name="folder-open" variant="outline" class="text-gray-400 size-12 mx-auto" />
                    </div>
                    <flux:heading class="text-gray-400">No class directories found</flux:heading>
                    <p class="text-gray-500 text-sm mt-2">Create a class directory to get started</p>
                </div>
            @else
                <div class="flex justify-between items-center mb-4 bg-gray-800/50 p-3 rounded-md">
                    <flux:heading>Classes</flux:heading>

                    <div class="flex items-center gap-3">
                        @if(isset($flash_message) && strlen($flash_message))
                            <flux:badge variant="solid" color="green">{{ $flash_message }}</flux:badge>
                        @endif

                        @if(!$check_proofs_uploaded)
                            <flux:button
                                wire:click="checkProofsUploaded"
                                size="sm"
                            >
                                Check Proofs & Web Images
                            </flux:button>
                        @else
                            @if(count($images_pending_upload) || count($web_images_pending_upload))
                                <div class="flex items-center gap-2">
                                    <flux:badge variant="solid" color="amber" size="sm">
                                        Proofs to upload: {{ count($images_pending_upload) }}
                                    </flux:badge>
                                    <flux:badge variant="solid" color="amber" size="sm">
                                        Web Images to upload: {{ count($web_images_pending_upload) }}
                                    </flux:badge>
                                    <flux:button
                                        wire:click="uploadPendingProofsAndWebImages"
                                        size="sm"
                                    >
                                        Upload
                                        @if(count($images_pending_upload))
                                            {{ number_format(count($images_pending_upload)) }} proofs
                                        @endif
                                        @if(count($web_images_pending_upload))
                                            @if(count($images_pending_upload))
                                                and
                                            @endif
                                            {{ number_format(count($web_images_pending_upload)) }} web images
                                        @endif
                                    </flux:button>
                                </div>
                            @else
                                <flux:badge variant="solid" color="green" size="sm">
                                    All proofs & web images uploaded
                                </flux:badge>
                            @endif
                        @endif
                    </div>
                </div>

                <flux:table class="!text-gray-300 hover">
                    <thead>
                        <tr>
                            <th>Class</th>
                            <th class="text-right">Photos Imported</th>
                            <th class="text-right">Photos to Import</th>
                            <th class="text-right">Photos to Proof</th>
                            <th class="text-right pr-2">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($class_folders as $key => $class_folder_data)
                        <tr class="hover:bg-gray-800/50">
                            <td class="flex items-center gap-2 min-h-8 pl-2">
                                <flux:icon name="folder" variant="outline" class="text-yellow-500" />
                                <a href="/show/{{ $show_id }}/class/{{ $class_folder_data['path'] }}"
                                   class="text-indigo-500 hover:text-indigo-400 hover:underline font-medium"
                                >
                                    {{ $class_folder_data['path'] }}
                                    @if($class_folder_data['show_class'] === null)
                                        <flux:badge variant="solid" color="red" size="sm">
                                            Not Imported
                                        </flux:badge>
                                    @endif
                                </a>
                            </td>
                            <td class="text-right">
                                @if($class_folder_data['show_class'])
                                    {{ $class_folder_data['show_class']->photos()->count() }}
                                @endif
                            </td>
                            <td class="text-right">
                                @if($class_folder_data['images_pending_processing_count'])
                                    <flux:badge variant="solid" color="sky" size="sm">
                                        {{ $class_folder_data['images_pending_processing_count'] }}
                                    </flux:badge>
                                @endif
                            </td>
                            <td class="text-right">
                                @if($class_folder_data['images_pending_proofing_count'])
                                    <flux:badge variant="solid" color="sky" size="sm">
                                        {{ $class_folder_data['images_pending_proofing_count'] }}
                                    </flux:badge>
                                @endif
                            </td>
                            <td class="text-right pr-2">
                                <div class="flex justify-end gap-2 my-0.5">
                                    @if($class_folder_data['images_pending_processing_count'])
                                        <flux:button
                                            wire:click="processPendingImages('{{ $class_folder_data['path'] }}')"
                                            x-data="{ isQueued: false }"
                                            x-on:click="isQueued = true"
                                            size="xs"
                                        >
                                            <span x-show="!isQueued">Import</span>
                                            <span x-show="isQueued">Queued</span>
                                        </flux:button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </flux:table>
            @endif
        </div>
    </div>
</div>
