<div class="relative" x-data="{ open: false }" @click.away="open = false">
    <label class="sr-only">Search for proof number</label>

    <div class="relative">
        <flux:input
            wire:model.live.debounce.300ms="query"
            placeholder="Search proofs..."
            icon="magnifying-glass"
            class="min-w-64 text-sm"
            @focus="open = true"
            @keydown.arrow-down.prevent="open = true"
            @keydown.arrow-up.prevent="open = true"
            @keydown.escape="open = false"
        />
        @if ($query)
            <button
                type="button"
                wire:click="clearSearch"
                class="absolute inset-y-0 right-2 flex items-center text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200"
                title="Clear search"
            >
                <flux:icon name="x-mark" class="w-4 h-4" />
            </button>
        @endif
    </div>

    {{-- Results dropdown --}}
    @if($showDropdown && count($results) > 0)
        <div
            x-show="open"
            x-transition:enter="transition ease-out duration-100"
            x-transition:enter-start="transform opacity-0 scale-95"
            x-transition:enter-end="transform opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-75"
            x-transition:leave-start="transform opacity-100 scale-100"
            x-transition:leave-end="transform opacity-0 scale-95"
            class="absolute right-0 z-50 mt-1 w-full max-h-72 overflow-auto rounded-lg shadow-lg
                   bg-white dark:bg-zinc-800
                   border border-zinc-200 dark:border-white/10
                   focus:outline-none"
        >
            <ul class="divide-y divide-zinc-100 dark:divide-white/5">
                @foreach($results as $result)
                    @php
                        $parts = explode('_', $result['show_class_id'], 2);
                        $show_name = $parts[0] ?? '';
                        $class_name = $parts[1] ?? '';
                    @endphp
                    <li>
                        <button
                            type="button"
                            wire:click="selectProof('{{ $result['id'] }}')"
                            class="w-full flex items-center justify-between gap-3 px-3 py-2.5 text-sm
                                   hover:bg-zinc-50 dark:hover:bg-white/[0.06]
                                   text-left transition-colors"
                        >
                            <span class="font-medium font-mono text-zinc-900 dark:text-white">{{ $result['proof_number'] }}</span>
                            <span class="flex items-center gap-1 text-xs text-zinc-500 dark:text-zinc-400">
                                <flux:badge color="zinc" size="sm">{{ $show_name }}</flux:badge>
                                <span class="text-zinc-400 dark:text-zinc-500">/</span>
                                <flux:badge color="zinc" size="sm">{{ $class_name }}</flux:badge>
                            </span>
                        </button>
                    </li>
                @endforeach
            </ul>
        </div>
    @elseif($query && strlen($query) >= 3 && count($results) === 0)
        <div
            x-show="open"
            class="absolute right-0 z-50 mt-1 w-full rounded-lg shadow-lg
                   bg-white dark:bg-zinc-800
                   border border-zinc-200 dark:border-white/10
                   px-3 py-3 text-sm text-zinc-500 dark:text-zinc-400"
        >
            No proofs found matching "<span class="font-mono text-zinc-700 dark:text-zinc-200">{{ $query }}</span>"
        </div>
    @endif
</div>
