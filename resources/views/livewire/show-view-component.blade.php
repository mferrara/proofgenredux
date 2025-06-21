<div class="px-8 py-6" wire:poll.5s>
    <div class="max-w-6xl mx-auto">
        <div class="flex justify-between items-center mb-6">
            <div class="flex items-center gap-3">
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

            @if(isset($flash_message) && strlen($flash_message))
                <flux:badge color="green" size="lg">{{ $flash_message }}</flux:badge>
            @endif
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
                <div class="flex flex-col gap-y-1">
                    <flux:heading size="xl">Show Details</flux:heading>

                    <div class="grid grid-cols-12 gap-6 mb-8">
                        <!-- Summary Stats -->
                        @include('components.partials.photo-process-status-table')

                        <!-- Action Panel -->
                        @include('components.partials.action-panel')
                    </div>
                </div>

                <flux:table class="!text-gray-300 hover">
                    <thead>
                        <tr>
                            <th>Class</th>
                            <th class="text-right">Photos Imported</th>
                            <th class="text-right">Photos to Import</th>
                            <th class="text-right">Needs Proofs/Web Images</th>
                            <th class="text-right pr-2">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($class_folders as $key => $class_folder_data)
                        <tr class="hover:bg-gray-800/50">
                            <td class="flex items-center gap-2 min-h-8 pl-2">
                                <flux:icon name="folder" variant="outline" class="{{ $class_folder_data['is_valid'] ? 'text-yellow-500' : 'text-red-500' }}" />
                                @if($class_folder_data['is_valid'])
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
                                @else
                                    <div x-data="{ 
                                        editing: false, 
                                        newName: '{{ $class_folder_data['path'] }}',
                                        originalName: '{{ $class_folder_data['path'] }}'
                                    }" class="flex items-center gap-2">
                                        <template x-if="!editing">
                                            <div class="flex items-center gap-2">
                                                <span class="text-gray-400 line-through">{{ $class_folder_data['path'] }}</span>
                                                <flux:badge variant="solid" color="red" size="sm">
                                                    Invalid Name
                                                </flux:badge>
                                                <flux:button 
                                                    @click="editing = true; $nextTick(() => $refs.input.focus())"
                                                    size="xs"
                                                    variant="ghost"
                                                >
                                                    <flux:icon name="pencil" variant="mini" />
                                                    Rename
                                                </flux:button>
                                            </div>
                                        </template>
                                        <template x-if="editing">
                                            <div class="flex items-center gap-2">
                                                <flux:input 
                                                    x-ref="input"
                                                    x-model="newName"
                                                    @keydown.enter="$wire.renameClassDirectory(originalName, newName); editing = false"
                                                    @keydown.escape="editing = false; newName = '{{ $class_folder_data['path'] }}'"
                                                    size="sm"
                                                    class="w-48"
                                                />
                                                <flux:button 
                                                    @click="$wire.renameClassDirectory(originalName, newName); editing = false"
                                                    size="xs"
                                                    variant="primary"
                                                >
                                                    <flux:icon name="check" variant="mini" />
                                                    Save
                                                </flux:button>
                                                <flux:button 
                                                    @click="editing = false; newName = '{{ $class_folder_data['path'] }}'"
                                                    size="xs"
                                                    variant="ghost"
                                                >
                                                    Cancel
                                                </flux:button>
                                            </div>
                                        </template>
                                    </div>
                                @endif
                            </td>
                            <td class="text-right">
                                @if($class_folder_data['is_valid'] && $class_folder_data['show_class'])
                                    {{ $class_folder_data['show_class']->photos()->count() }}
                                @elseif(!$class_folder_data['is_valid'])
                                    <span class="text-gray-500">-</span>
                                @endif
                            </td>
                            <td class="text-right">
                                @if($class_folder_data['is_valid'] && $class_folder_data['images_pending_processing_count'])
                                    <flux:badge variant="solid" color="sky" size="sm">
                                        {{ $class_folder_data['images_pending_processing_count'] }}
                                    </flux:badge>
                                @elseif(!$class_folder_data['is_valid'])
                                    <span class="text-gray-500">-</span>
                                @endif
                            </td>
                            <td class="text-right">
                                @if($class_folder_data['show_class'])
                                    @if($class_folder_data['show_class']->photos()->whereNull('proofs_generated_at')->count())
                                        <flux:badge color="blue" size="sm">
                                            Proofs: {{ $class_folder_data['show_class']->photos()->whereNull('proofs_generated_at')->count() }}
                                        </flux:badge>
                                    @endif
                                    @if($class_folder_data['show_class']->photos()->whereNotNull('proofs_generated_at')->whereNull('proofs_uploaded_at')->count())
                                        <flux:badge color="blue" size="sm">
                                            Proofs Upload: {{ $class_folder_data['show_class']->photos()->whereNotNull('proofs_generated_at')->whereNull('proofs_uploaded_at')->count() }}
                                        </flux:badge>
                                    @endif
                                    @if($class_folder_data['show_class']->photos()->whereNull('web_image_generated_at')->count())
                                        <flux:badge color="cyan" size="sm">
                                            Web Gen: {{ $class_folder_data['show_class']->photos()->whereNull('web_image_generated_at')->count() }}
                                        </flux:badge>
                                    @endif
                                    @if($class_folder_data['show_class']->photos()->whereNotNull('web_image_generated_at')->whereNull('web_image_uploaded_at')->count())
                                        <flux:badge color="cyan" size="sm">
                                            Web Upload: {{ $class_folder_data['show_class']->photos()->whereNotNull('web_image_generated_at')->whereNull('web_image_uploaded_at')->count() }}
                                        </flux:badge>
                                    @endif
                                    @if($class_folder_data['show_class']->photos()->whereNull('highres_image_generated_at')->count())
                                        <flux:badge color="purple" size="sm">
                                            Highres Gen: {{ $class_folder_data['show_class']->photos()->whereNull('highres_image_generated_at')->count() }}
                                        </flux:badge>
                                    @endif
                                    @if($class_folder_data['show_class']->photos()->whereNotNull('highres_image_generated_at')->whereNull('highres_image_uploaded_at')->count())
                                        <flux:badge color="purple" size="sm">
                                            Highres Upload: {{ $class_folder_data['show_class']->photos()->whereNotNull('highres_image_generated_at')->whereNull('highres_image_uploaded_at')->count() }}
                                        </flux:badge>
                                    @endif
                                @endif
                            </td>
                            <td class="text-right pr-2">
                                <div class="flex justify-end gap-2 my-0.5">
                                    @if($class_folder_data['is_valid'] && $class_folder_data['images_pending_processing_count'])
                                        <flux:button
                                            wire:click="processPendingClassImages('{{ $class_folder_data['path'] }}')"
                                            x-data="{ isQueued: false }"
                                            x-on:click="isQueued = true"
                                            size="xs"
                                        >
                                            <span x-show="!isQueued">Import</span>
                                            <span x-show="isQueued">Queued</span>
                                        </flux:button>
                                    @elseif(!$class_folder_data['is_valid'])
                                        <flux:tooltip content="{{ $class_folder_data['validation_error'] }}">
                                            <flux:button size="xs" variant="outline" disabled>
                                                <flux:icon name="exclamation-triangle" variant="mini" />
                                                Cannot Import
                                            </flux:button>
                                        </flux:tooltip>
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
