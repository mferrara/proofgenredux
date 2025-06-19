<div class="px-8 py-6">
    <div class="mt-2 mx-auto max-w-4xl flex flex-col justify-start">
        <div class="flex flex-row justify-between items-center">
            <flux:heading size="xl" class="mb-6">Select Show</flux:heading>
            <flux:modal.trigger name="create-show">
                <flux:button size="sm" variant="ghost" class="!text-indigo-400/80 ml-3 hover:cursor-pointer">
                    Create Show
                </flux:button>
            </flux:modal.trigger>
        </div>

        <!-- Create Show Modal -->
        <flux:modal
            name="create-show"
            class="max-w-md"
            x-on:close="$wire.set('newShowName', '')"
            x-on:shown="setTimeout(() => document.querySelector('#create-show-input').focus(), 100)"
        >
            <div class="space-y-6">
                <flux:heading size="lg">Create New Show</flux:heading>
                <p class="text-gray-400">Enter a folder name for the new show. This will create a directory in the base folder.</p>

                <form wire:submit="createShow" class="space-y-4" x-on:keydown.enter.prevent="$event.target.form?.requestSubmit()">
                    <flux:input
                        id="create-show-input"
                        wire:model.live="newShowName"
                        label="Show Name"
                        placeholder="Enter show name (no spaces)"
                        x-data="{}"
                        x-on:keydown="if ($event.key === ' ') $event.preventDefault()"
                        pattern="[A-Za-z0-9_\-]+"
                        title="Show name can only contain letters, numbers, underscores and hyphens"
                        required
                    />
                    <div class="text-xs text-gray-500">Use letters, numbers, underscores and hyphens only</div>

                    <div class="flex justify-end gap-3 pt-2">
                        <flux:modal.close>
                            <flux:button variant="ghost">Cancel</flux:button>
                        </flux:modal.close>

                        <flux:button
                            type="submit"
                            variant="primary"
                        >
                            Create Show
                        </flux:button>
                    </div>
                </form>
            </div>
        </flux:modal>

        <div class="grid gap-3">
            @foreach($top_level_directories as $directory)
                @if($directory === 'web_images' || $directory === 'proofs' || $directory === 'highres_images') @continue @endif
                @php
                    $folder_name = explode('/', $directory);
                    $folder_name = end($folder_name);
                    $show = null;
                    $show_pending_images = 0;
                    if(isset($shows[$folder_name])) {
                        $show = $shows[$folder_name];
                        $show_pending_images = collect($show->getImagesPendingImport());
                    }
                @endphp

                <div class="pl-2 flex flex-row justify-between items-center py-2 px-3
                bg-gray-800/50 rounded-md hover:bg-gray-800/80 transition-colors
                ">
                    <div class="flex flex-row items-center gap-x-3">
                        @if(isset($shows[$folder_name]))
                            <flux:icon name="folder" variant="outline" class="text-yellow-500" />
                            <a href="/show/{{ $folder_name }}" class="text-lg font-medium text-indigo-400 hover:text-indigo-300 hover:underline">
                                {{ $folder_name }}
                            </a>
                        @else
                            <flux:icon name="folder" variant="outline" class="text-yellow-500/40" />
                            <div class="text-lg font-medium text-indigo-400/40 hover:text-indigo-300 hover:line-through hover:cursor-not-allowed">{{ $folder_name }}</div>
                        @endif
                    </div>
                    <div class="flex flex-row items-center justify-end gap-x-2">
                        @if(isset($shows[$folder_name]))
                            <flux:badge color="cyan" size="sm">
                                {{ $shows[$folder_name]->classes()->count() }} {{ str('Class')->plural($shows[$folder_name]->classes()->count()) }}
                            </flux:badge>
                            @if($show && $show->photos->count() > 0)
                                <flux:badge color="sky" size="sm">
                                    {{ $show->photos->count() }} {{ str('Photo')->plural($show->photos->count()) }}
                                </flux:badge>
                            @endif
                            @if($show && $show_pending_images->count())
                                <flux:badge color="amber" size="sm">
                                    {{ $show_pending_images->count() }} {{ str('Image')->plural($show_pending_images->count()) }} to import
                                </flux:badge>
                            @endif
                        @endif
                        @if( !isset($shows[$folder_name]))
                            <flux:badge color="cyan" size="sm">
                                Show not imported
                            </flux:badge>
                            <flux:button wire:click="createShow('{!! $folder_name !!}')" size="xs" class="ml-3 hover:cursor-pointer">
                                Import
                            </flux:button>
                        @endif
                    </div>
                </div>
            @endforeach

            @if(count($top_level_directories) === 0 || (count($top_level_directories) === 3 && in_array('web_images', $top_level_directories) && in_array('proofs', $top_level_directories) && in_array('highres_images', $top_level_directories)))
                <div class="py-8 text-center">
                    <div class="mb-4">
                        <flux:icon name="folder-open" variant="outline" class="text-gray-400 size-12 mx-auto" />
                    </div>
                    <flux:heading class="text-gray-400">No shows available</flux:heading>
                    <p class="text-gray-500 text-sm mt-2">Create a new directory in the root folder to get started</p>
                </div>
            @endif
        </div>
    </div>
</div>
