<div class="px-6 lg:px-10 py-6 max-w-4xl mx-auto">
    <div class="mb-6 flex items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1" class="!text-3xl !font-semibold tracking-tight">Shows</flux:heading>
            <flux:text class="mt-1">
                Browse photography events stored in <code class="font-mono text-xs">{{ $fullsize_base_path }}</code>.
            </flux:text>
        </div>
        <flux:modal.trigger name="create-show">
            <flux:button variant="primary" icon="plus">Create Show</flux:button>
        </flux:modal.trigger>
    </div>

    {{-- Create Show Modal --}}
    <flux:modal
        name="create-show"
        class="max-w-md"
        x-on:close="$wire.set('newShowName', '')"
        x-on:shown="setTimeout(() => document.querySelector('#create-show-input').focus(), 100)"
    >
        <div class="space-y-6">
            <flux:heading size="lg">Create new show</flux:heading>
            <flux:text>Enter a folder name for the new show. This creates a directory in the base folder.</flux:text>

            <form wire:submit="createShow" class="space-y-4" x-on:keydown.enter.prevent="$event.target.form?.requestSubmit()">
                <flux:field>
                    <flux:label>Show name</flux:label>
                    <flux:input
                        id="create-show-input"
                        wire:model.live="newShowName"
                        placeholder="e.g. 2026R12"
                        x-data="{}"
                        x-on:keydown="if ($event.key === ' ') $event.preventDefault()"
                        pattern="[A-Za-z0-9_\-]+"
                        title="Show name can only contain letters, numbers, underscores and hyphens"
                        required
                    />
                    <flux:description>Letters, numbers, underscores, and hyphens only — no spaces.</flux:description>
                </flux:field>

                <div class="flex justify-end gap-2 pt-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancel</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">Create show</flux:button>
                </div>
            </form>
        </div>
    </flux:modal>

    @php
        $visibleDirectories = collect($top_level_directories)
            ->reject(fn ($dir) => in_array(basename($dir), ['proofs', 'web_images', 'highres_images']))
            ->values();
    @endphp

    @if($visibleDirectories->isEmpty())
        <flux:card class="!p-10 text-center">
            <flux:icon name="folder-open" class="size-12 mx-auto text-zinc-400 dark:text-zinc-500 mb-3" />
            <flux:heading size="base" class="!font-medium">No shows available</flux:heading>
            <flux:text class="mt-1">
                Create a show with the button above, or add a folder directly to
                <code class="font-mono text-xs">{{ $fullsize_base_path }}</code>.
            </flux:text>
        </flux:card>
    @else
        <div class="space-y-2">
            @foreach($visibleDirectories as $directory)
                @php
                    $folder_name = basename($directory);
                    $show = $shows[$folder_name] ?? null;
                    $pendingImports = $show ? collect($show->getImagesPendingImport()) : collect();
                    $isImported = $show !== null;
                @endphp

                <div class="group flex items-center justify-between gap-3 rounded-lg px-4 py-3
                            border border-zinc-200 dark:border-white/10
                            bg-white dark:bg-white/[0.04]
                            hover:bg-zinc-50 dark:hover:bg-white/[0.07]
                            transition-colors">
                    <div class="flex items-center gap-3 min-w-0">
                        <flux:icon
                            name="folder"
                            variant="outline"
                            class="size-5 shrink-0 {{ $isImported ? 'text-amber-500' : 'text-zinc-400 dark:text-zinc-600' }}"
                        />
                        @if($isImported)
                            <a href="/show/{{ $folder_name }}"
                               class="font-medium text-zinc-900 dark:text-white hover:underline underline-offset-2 truncate">
                                {{ $folder_name }}
                            </a>
                        @else
                            <span class="font-medium text-zinc-500 dark:text-zinc-500 truncate">
                                {{ $folder_name }}
                            </span>
                        @endif
                    </div>

                    <div class="flex items-center gap-2 shrink-0">
                        @if($isImported)
                            <flux:badge color="zinc" size="sm">
                                {{ $show->classes()->count() }} {{ str('Class')->plural($show->classes()->count()) }}
                            </flux:badge>
                            @if($show->photos->count() > 0)
                                <flux:badge color="sky" size="sm">
                                    {{ number_format($show->photos->count()) }} {{ str('Photo')->plural($show->photos->count()) }}
                                </flux:badge>
                            @endif
                            @if($pendingImports->count())
                                <flux:badge color="amber" size="sm">
                                    {{ $pendingImports->count() }} pending
                                </flux:badge>
                            @endif
                        @else
                            <flux:badge color="zinc" size="sm">Not imported</flux:badge>
                            <flux:button wire:click="createShow('{!! $folder_name !!}')" size="xs" variant="primary">
                                Import
                            </flux:button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="mt-8">
        @include('livewire.partials.misc-storage-panel', ['misc_storage' => $misc_storage])
    </div>
</div>
