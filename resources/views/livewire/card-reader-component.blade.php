@php
    $images = $scan['images'] ?? [];
    $bytes = fn (int $b) => $b >= 1_000_000_000 ? round($b / 1_000_000_000, 1).' GB' : round($b / 1_000_000).' MB';
    $when = fn (int $t) => date('g:i a', $t);
    $thumb = fn (array $image) => route('card-thumb', ['m' => $volume?->mountPoint, 'p' => $image['path']]);
    $likelyImported = count(array_filter($images, fn ($i) => $i['likely_imported']));
    $state = $progress['state'] ?? null;
@endphp

<div>
    <flux:modal.trigger name="card-reader">
        <flux:button size="sm" variant="primary" icon="arrow-down-tray">Card Reader</flux:button>
    </flux:modal.trigger>

    <flux:modal name="card-reader" class="{{ $mode === 'split' ? 'w-[min(96vw,1200px)]' : 'w-[min(96vw,640px)]' }} max-w-none">
        <div class="space-y-5" wire:poll.2s="watchReader">
            <div class="flex items-center justify-between gap-3">
                <flux:heading size="lg">Card Reader — {{ $show_id }}</flux:heading>
                @if ($volume && ! $dumping)
                    <flux:button size="xs" variant="ghost" icon="arrow-up-on-square" wire:click="ejectNow">Eject</flux:button>
                @endif
            </div>

            {{-- Source: pick a reader once; the next card put into it is selected automatically. --}}
            <div>
                <flux:label>Card</flux:label>
                @if ($volumes === [])
                    <flux:text class="mt-1">No card found. Put a card in a reader.</flux:text>
                @else
                    <div class="mt-1 grid gap-2">
                        @foreach ($volumes as $candidate)
                            <button type="button" wire:click="selectVolume(@js($candidate->mountPoint))" @disabled($dumping)
                                class="flex items-center justify-between gap-3 rounded-lg border px-3 py-2 text-left text-sm
                                    {{ $volume?->mountPoint === $candidate->mountPoint ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-500/10' : 'border-zinc-200 dark:border-white/10 hover:border-zinc-400' }}">
                                <span class="font-medium">{{ $candidate->label() }}</span>
                                @if (! $candidate->hasDcim)
                                    <flux:badge size="sm" color="zinc">no camera folder</flux:badge>
                                @endif
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            @if ($volume && ! $volume->readable)
                <flux:callout variant="danger" icon="lock-closed" heading="macOS is not letting Proofgen read this card">
                    Open System Settings → Privacy &amp; Security → Full Disk Access and turn it on for <strong>Herd</strong>, then
                    restart the background workers from the page header and press Rescan.
                    <a class="underline" href="x-apple.systempreferences:com.apple.preference.security?Privacy_AllFiles">Open that setting</a>.
                </flux:callout>
                <flux:button size="sm" wire:click="rescan">Rescan</flux:button>
            @endif

            @if ($progress)
                {{-- A dump is running or just finished. --}}
                <div class="rounded-lg border border-zinc-200 dark:border-white/10 p-4 space-y-3">
                    <div class="flex items-center justify-between text-sm">
                        <span class="font-medium">
                            @switch($state)
                                @case('copying') Copying… @break
                                @case('clearing') Clearing the card… @break
                                @case('ejecting') Ejecting… @break
                                @case('done') Done @break
                                @case('failed') Stopped @break
                                @default Starting…
                            @endswitch
                        </span>
                        <span>{{ $progress['done'] }} / {{ $progress['total'] }} · {{ $bytes($progress['bytes']) }}</span>
                    </div>
                    <div class="h-2 rounded bg-zinc-200 dark:bg-white/10 overflow-hidden">
                        <div class="h-full {{ $state === 'failed' ? 'bg-rose-500' : 'bg-emerald-500' }}"
                             style="width: {{ $progress['total'] ? round($progress['done'] / $progress['total'] * 100) : 0 }}%"></div>
                    </div>
                    @if ($progress['current'])
                        <flux:text class="!text-xs font-mono">{{ $progress['current'] }}</flux:text>
                    @endif
                    @if ($state === 'done')
                        <flux:text class="!text-sm">
                            {{ $progress['copied'] }} copied{{ $archiveUsable ? ' to the class folder and the archive' : ' to the class folder (no archive drive)' }}.
                            @if ($progress['already_imported']) {{ $progress['already_imported'] }} were already imported. @endif
                            @if ($progress['already_present']) {{ $progress['already_present'] }} were already in the class folder. @endif
                            @if ($progress['cleared']) {{ $progress['cleared'] }} removed from the card. @endif
                            {{ $progress['message'] }}
                        </flux:text>
                    @endif
                    @foreach ($progress['errors'] as $error)
                        <flux:text class="!text-sm text-rose-600 dark:text-rose-400">{{ $error }} {{ $progress['message'] }}</flux:text>
                    @endforeach
                    @if (! $dumping)
                        <flux:button variant="primary" wire:click="nextCard">Next card</flux:button>
                    @endif
                </div>
            @elseif ($volume && $volume->readable && $scan)
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <flux:badge color="indigo">{{ count($images) }} photos · {{ $bytes($scan['total_bytes']) }}</flux:badge>
                    @if ($images)
                        <flux:badge color="zinc">{{ $when($images[0]['taken_at']) }} – {{ $when($images[array_key_last($images)]['taken_at']) }}</flux:badge>
                        <flux:badge color="zinc">{{ $images[0]['name'] }} … {{ $images[array_key_last($images)]['name'] }}</flux:badge>
                    @endif
                    @if ($likelyImported)
                        <flux:badge color="amber" icon="exclamation-triangle">{{ $likelyImported }} look already imported</flux:badge>
                    @endif
                    @foreach ($scan['skipped'] as $extension => $count)
                        <flux:badge color="zinc" title="Not imported and never removed from the card">{{ $count }} .{{ $extension }} left on card</flux:badge>
                    @endforeach
                </div>

                @if ($images === [])
                    <flux:text>This card has no photos on it.</flux:text>
                @else
                    <flux:radio.group wire:model.live="mode" variant="segmented" size="sm">
                        <flux:radio value="single" label="One class" />
                        <flux:radio value="split" label="Split into multiple classes" />
                    </flux:radio.group>

                    @if ($mode === 'single')
                        <flux:field>
                            <flux:label>Class</flux:label>
                            <flux:select variant="listbox" searchable wire:model.live="classFolder" placeholder="Choose the class…">
                                @foreach ($classes as $class)
                                    <flux:select.option value="{{ $class }}">{{ $class }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:error name="classFolder" />
                        </flux:field>
                    @else
                        <div class="flex items-end gap-3">
                            <flux:field class="w-40">
                                <flux:label>New class after a pause of</flux:label>
                                <flux:input type="number" min="1" max="240" wire:model.live.debounce.500ms="gapMinutes" size="sm">
                                    <x-slot name="iconTrailing"><span class="text-xs text-zinc-500">min</span></x-slot>
                                </flux:input>
                            </flux:field>
                            <flux:text class="!text-xs pb-2">Click a photo to start a new class there. Groups left without a class stay on the card.</flux:text>
                        </div>
                        <flux:error name="classFolder" />

                        <div class="space-y-4 max-h-[55vh] overflow-y-auto pr-1">
                            @foreach ($groups as $g => $indexes)
                                @php $first = $images[$indexes[0]]; $last = $images[$indexes[array_key_last($indexes)]]; @endphp
                                <div wire:key="group-{{ $g }}-{{ $indexes[0] }}" class="rounded-lg border border-zinc-200 dark:border-white/10 p-3">
                                    <div class="flex flex-wrap items-center gap-3 mb-2">
                                        <div class="w-56">
                                            <flux:select variant="listbox" searchable size="sm" wire:model.live="groupClasses.{{ $g }}" placeholder="Class for this group…">
                                                @foreach ($classes as $class)
                                                    <flux:select.option value="{{ $class }}">{{ $class }}</flux:select.option>
                                                @endforeach
                                            </flux:select>
                                        </div>
                                        <flux:text class="!text-sm">{{ count($indexes) }} photos · {{ $when($first['taken_at']) }} – {{ $when($last['taken_at']) }} · {{ $first['name'] }} … {{ $last['name'] }}</flux:text>
                                        @if ($g > 0)
                                            <flux:button size="xs" variant="ghost" icon="arrow-up" wire:click="mergeUp({{ $g }})">Join with previous</flux:button>
                                        @endif
                                    </div>
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($indexes as $position => $index)
                                            <button type="button" wire:click="splitAt({{ $index }})" @disabled($position === 0)
                                                title="{{ $images[$index]['name'] }} · {{ date('g:i:s a', $images[$index]['taken_at']) }}{{ $position ? ' — start a new class here' : '' }}"
                                                class="relative h-16 w-24 overflow-hidden rounded bg-zinc-200 dark:bg-white/10 {{ $position ? 'hover:ring-2 hover:ring-indigo-500' : '' }}">
                                                <img src="{{ $thumb($images[$index]) }}" loading="lazy" alt="" class="h-full w-full object-cover" />
                                                @if ($images[$index]['likely_imported'])
                                                    <span class="absolute inset-x-0 bottom-0 bg-amber-500/90 text-[10px] text-white">imported</span>
                                                @endif
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <div class="flex items-end gap-2">
                        <flux:field class="flex-1">
                            <flux:label>New class</flux:label>
                            <flux:input size="sm" wire:model="newClassName" wire:keydown.enter="createClass" placeholder="e.g. 012" class="font-mono" />
                            <flux:error name="newClassName" />
                        </flux:field>
                        <flux:button size="sm" wire:click="createClass">Add</flux:button>
                    </div>

                    <div class="space-y-2 rounded-lg border border-zinc-200 dark:border-white/10 p-3">
                        <flux:switch wire:model.live="importNow" label="Start importing right away" description="Off: just copy the cards now and import later." />
                        <flux:switch wire:model.live="clearCard" :disabled="! $archiveUsable" label="Empty the card when both copies are verified"
                            description="{{ $archiveUsable ? 'Removes only photos that were copied to the class folder AND the archive and read back correctly. Anything else stays on the card.' : 'Needs the archive drive: turn Backups on and plug the drive in.' }}" />
                        <flux:switch wire:model.live="ejectWhenDone" label="Eject when finished" />
                    </div>

                    <flux:button variant="primary" class="w-full !h-12 !text-base" wire:click="startDump" wire:loading.attr="disabled">
                        Copy {{ $mode === 'single' ? count($images).' photos' : 'the assigned groups' }}
                    </flux:button>
                @endif
            @endif
        </div>
    </flux:modal>
</div>
