<div class="px-6 lg:px-10 py-6 max-w-[1400px] mx-auto" wire:poll.5s>
    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <flux:button
                href="/"
                size="sm"
                variant="ghost"
                square
                icon="chevron-left"
                tooltip="Back to shows"
            />
            <div>
                <div class="text-xs uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Show</div>
                <flux:heading size="xl" level="1" class="!text-3xl !font-semibold tracking-tight">{{ $show_id }}</flux:heading>
            </div>
            <div wire:loading class="ml-2">
                <flux:badge color="sky" size="sm" class="animate-pulse">Working…</flux:badge>
            </div>
        </div>

        <div class="flex items-center gap-3">
            @if(isset($flash_message) && strlen($flash_message))
                <flux:badge color="emerald" size="sm">{{ $flash_message }}</flux:badge>
            @endif
            @if(($show_open_issue_count ?? 0) > 0)
                <a href="{{ route('photo-issues', ['show_id' => $show_id]) }}">
                    <flux:badge color="amber" size="sm" icon="exclamation-triangle">
                        {{ $show_open_issue_count }} {{ str('issue')->plural($show_open_issue_count) }}
                    </flux:badge>
                </a>
            @endif
            <flux:button
                size="sm"
                variant="ghost"
                square
                icon="folder-open"
                tooltip="Open show folder in Finder"
                wire:click="openFolder('{{ $show->full_path }}')"
            />
        </div>
    </div>

    @if(count($current_path_directories) === 0)
        <flux:card class="!p-10 text-center">
            <flux:icon name="folder-open" class="size-12 mx-auto text-zinc-400 dark:text-zinc-500 mb-3" />
            <flux:heading size="base" class="!font-medium">No class directories found</flux:heading>
            <flux:text class="mt-1">Create a class directory inside this show's folder to get started.</flux:text>
        </flux:card>
    @else
        {{-- Status snapshot + actions --}}
        <div class="grid grid-cols-12 gap-4 mb-8">
            @include('components.partials.photo-process-status-table')
            @include('components.partials.action-panel')
        </div>

        <div class="mb-8 grid gap-4 md:grid-cols-2">
            @include('livewire.partials.storage-usage-panel', ['storage_usage' => $storage_usage])
            @include('livewire.partials.ferraraphoto-status-panel', ['ferraraphoto_status' => $ferraraphoto_status])
        </div>

        {{-- Class folders --}}
        <flux:heading size="lg" level="2" class="mb-3">Classes</flux:heading>
        <flux:card class="!p-0 overflow-hidden">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Class</flux:table.column>
                    <flux:table.column align="end">Imported</flux:table.column>
                    <flux:table.column align="end">To import</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column align="end">Actions</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($class_folders as $class_folder_data)
                        @php
                            $sc = $class_folder_data['show_class'] ?? null;
                        @endphp
                        <flux:table.row>
                            <flux:table.cell>
                                <div class="flex items-center gap-2">
                                    <flux:icon
                                        name="folder"
                                        variant="outline"
                                        class="size-4 shrink-0 {{ $class_folder_data['is_valid'] ? 'text-amber-500' : 'text-rose-500' }}"
                                    />
                                    @if($class_folder_data['is_valid'])
                                        <a href="/show/{{ $show_id }}/class/{{ $class_folder_data['path'] }}"
                                           class="font-medium text-zinc-900 dark:text-white hover:underline underline-offset-2">
                                            {{ $class_folder_data['path'] }}
                                        </a>
                                        @if($sc === null)
                                            <flux:badge color="zinc" size="sm">Not imported</flux:badge>
                                        @endif
                                    @else
                                        <div x-data="{
                                            editing: false,
                                            newName: '{{ $class_folder_data['path'] }}',
                                            originalName: '{{ $class_folder_data['path'] }}'
                                        }" class="flex items-center gap-2">
                                            <template x-if="!editing">
                                                <div class="flex items-center gap-2">
                                                    <span class="text-zinc-400 dark:text-zinc-500 line-through">{{ $class_folder_data['path'] }}</span>
                                                    <flux:badge color="rose" size="sm">Invalid name</flux:badge>
                                                    <flux:button
                                                        @click="editing = true; $nextTick(() => $refs.input.focus())"
                                                        size="xs"
                                                        variant="ghost"
                                                        icon="pencil"
                                                    >
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
                                                        @keydown.escape="editing = false; newName = originalName"
                                                        size="sm"
                                                        class="w-44"
                                                    />
                                                    <flux:button
                                                        @click="$wire.renameClassDirectory(originalName, newName); editing = false"
                                                        size="xs"
                                                        variant="primary"
                                                        icon="check"
                                                    >
                                                        Save
                                                    </flux:button>
                                                    <flux:button
                                                        @click="editing = false; newName = originalName"
                                                        size="xs"
                                                        variant="ghost"
                                                    >
                                                        Cancel
                                                    </flux:button>
                                                </div>
                                            </template>
                                        </div>
                                    @endif
                                </div>
                            </flux:table.cell>

                            <flux:table.cell align="end">
                                @if($class_folder_data['is_valid'] && $sc)
                                    <span class="font-mono text-sm text-zinc-700 dark:text-zinc-300">{{ number_format($sc->photos()->count()) }}</span>
                                @else
                                    <span class="text-zinc-400 dark:text-zinc-600">—</span>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell align="end">
                                @if($class_folder_data['is_valid'] && $class_folder_data['images_pending_processing_count'])
                                    <flux:badge color="amber" size="sm">{{ number_format($class_folder_data['images_pending_processing_count']) }}</flux:badge>
                                @else
                                    <span class="text-zinc-400 dark:text-zinc-600">—</span>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell>
                                @if($sc)
                                    <div class="flex flex-wrap gap-1">
                                        @php
                                            $proofsPending = $sc->photos()->whereNull('proofs_generated_at')->count();
                                            $proofUpload = $sc->photos()->whereNotNull('proofs_generated_at')->whereNull('proofs_uploaded_at')->count();
                                            $webPending = $sc->photos()->whereNull('web_image_generated_at')->count();
                                            $webUpload = $sc->photos()->whereNotNull('web_image_generated_at')->whereNull('web_image_uploaded_at')->count();
                                            $highresPending = $sc->photos()->whereNull('highres_image_generated_at')->count();
                                            $highresUpload = $sc->photos()->whereNotNull('highres_image_generated_at')->whereNull('highres_image_uploaded_at')->count();
                                        @endphp
                                        @if($proofsPending) <flux:badge color="blue" size="sm">{{ $proofsPending }} proofs</flux:badge> @endif
                                        @if($proofUpload) <flux:badge color="blue" size="sm">{{ $proofUpload }} proof upload</flux:badge> @endif
                                        @if($webPending) <flux:badge color="cyan" size="sm">{{ $webPending }} web</flux:badge> @endif
                                        @if($webUpload) <flux:badge color="cyan" size="sm">{{ $webUpload }} web upload</flux:badge> @endif
                                        @if($highresPending) <flux:badge color="purple" size="sm">{{ $highresPending }} highres</flux:badge> @endif
                                        @if($highresUpload) <flux:badge color="purple" size="sm">{{ $highresUpload }} highres upload</flux:badge> @endif
                                        @if($proofsPending + $proofUpload + $webPending + $webUpload + $highresPending + $highresUpload === 0)
                                            <flux:badge color="emerald" size="sm" icon="check">All done</flux:badge>
                                        @endif
                                        @if(($class_folder_data['open_issue_count'] ?? 0) > 0)
                                            <a href="{{ route('photo-issues', ['show_class_id' => $sc->id]) }}">
                                                <flux:badge color="amber" size="sm" icon="exclamation-triangle">
                                                    {{ $class_folder_data['open_issue_count'] }} {{ str('issue')->plural($class_folder_data['open_issue_count']) }}
                                                </flux:badge>
                                            </a>
                                        @endif
                                    </div>
                                @else
                                    <span class="text-zinc-400 dark:text-zinc-600">—</span>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell>
                                <div class="flex items-center justify-end gap-2">
                                    @if($class_folder_data['is_valid'] && $class_folder_data['images_pending_processing_count'])
                                        <flux:button
                                            wire:click="processPendingClassImages('{{ $class_folder_data['path'] }}')"
                                            x-data="{ isQueued: false }"
                                            x-on:click="isQueued = true"
                                            size="xs"
                                            variant="primary"
                                        >
                                            <span x-show="!isQueued">Import</span>
                                            <span x-show="isQueued">Queued</span>
                                        </flux:button>
                                    @elseif(!$class_folder_data['is_valid'])
                                        <flux:button
                                            size="xs"
                                            variant="outline"
                                            disabled
                                            tooltip="{{ $class_folder_data['validation_error'] }}"
                                            icon="exclamation-triangle"
                                        >
                                            Cannot import
                                        </flux:button>
                                    @endif

                                    @if($class_folder_data['is_valid'] && $sc)
                                        <div x-data="{
                                            renaming: false,
                                            newName: '{{ $class_folder_data['path'] }}',
                                            originalName: '{{ $class_folder_data['path'] }}'
                                        }">
                                            <flux:dropdown position="bottom" align="end">
                                                <flux:button size="xs" variant="ghost" square icon="ellipsis-horizontal" />
                                                <flux:navmenu>
                                                    <flux:navmenu.item
                                                        @click="renaming = true; $nextTick(() => $refs.renameInput.focus())"
                                                        icon="pencil"
                                                    >
                                                        Rename
                                                    </flux:navmenu.item>
                                                </flux:navmenu>
                                            </flux:dropdown>

                                            <template x-if="renaming">
                                                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
                                                     @click.self="renaming = false; newName = originalName">
                                                    <div class="rounded-xl p-6 max-w-md w-full mx-4
                                                                bg-white dark:bg-zinc-900
                                                                border border-zinc-200 dark:border-white/10
                                                                shadow-2xl">
                                                        <flux:heading size="lg" class="mb-4">Rename class</flux:heading>
                                                        <flux:field>
                                                            <flux:label>New name</flux:label>
                                                            <flux:input
                                                                x-ref="renameInput"
                                                                x-model="newName"
                                                                @keydown.enter="$wire.renameImportedClass(originalName, newName); renaming = false"
                                                                @keydown.escape="renaming = false; newName = originalName"
                                                                placeholder="Enter new class name"
                                                            />
                                                        </flux:field>
                                                        <div class="flex justify-end gap-2 mt-4">
                                                            <flux:button
                                                                @click="renaming = false; newName = originalName"
                                                                variant="ghost"
                                                            >
                                                                Cancel
                                                            </flux:button>
                                                            <flux:button
                                                                @click="$wire.renameImportedClass(originalName, newName); renaming = false"
                                                                variant="primary"
                                                                icon="check"
                                                            >
                                                                Rename
                                                            </flux:button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    @endif
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @endif
</div>
