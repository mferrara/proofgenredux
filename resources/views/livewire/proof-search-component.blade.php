<div class="relative" x-data="{ open: false }" @click.away="open = false">
    <label class="sr-only">Search for proof number</label>
    <div class="flex items-center">
        <flux:input
            wire:model.live.debounce.300ms="query"
            placeholder="Search proofs..."
            class="min-w-64 text-sm"
            @focus="open = true"
            @keydown.arrow-down.prevent="open = true"
            @keydown.arrow-up.prevent="open = true"
            @keydown.escape="open = false"
        />
        @if ($query)
            <button
                wire:click="clearSearch"
                class="absolute right-0 inset-y-0 flex items-center px-3 text-gray-400 hover:text-gray-500"
                title="Clear search"
            >
                <flux:icon name="x-mark" class="w-4 h-4" />
            </button>
        @endif
    </div>

    <!-- Dropdown menu with autocomplete results -->
    @if($showDropdown && count($results) > 0)
        <div
            class="absolute right-0 z-50 mt-1 max-h-60 w-full overflow-auto rounded-md bg-zinc-800 py-1
            text-zinc-300 shadow-lg shadow-zinc-800 ring-1 ring-zinc-900 ring-opacity-5 focus:outline-none sm:text-sm"
            x-show="open"
            x-transition:enter="transition ease-out duration-100"
            x-transition:enter-start="transform opacity-0 scale-95"
            x-transition:enter-end="transform opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-75"
            x-transition:leave-start="transform opacity-100 scale-100"
            x-transition:leave-end="transform opacity-0 scale-95"
        >
            <ul class="divide-y divide-zinc-400">
                @foreach($results as $result)
                    @php
                        $show_class_data = explode('_', $result['show_class_id']);
                        $show_name = $show_class_data[0] ?? '';
                        $class_name = $show_class_data[1] ?? '';
                    @endphp
                    <li>
                        <button
                            wire:click="selectProof('{{ $result['id'] }}')"
                            class="flex w-full items-center px-3 py-2 text-sm hover:bg-zinc-700 hover:cursor-pointer"
                        >
                            <div class="flex flex-col items-start gap-1">
                                <span class="font-medium py-2">{{ $result['proof_number'] }}</span>
                                <span class="text-xs text-gray-400 ml-2">
                                    Show: <span class="text-yellow-500">{{ $show_name }}</span>
                                    Class: <span class="text-yellow-500">{{ $class_name }}</span>
                                </span>
                            </div>
                        </button>
                    </li>
                @endforeach
            </ul>
        </div>
    @elseif($query && strlen($query) >= 3 && count($results) === 0)
        <div
            class="absolute right-0 z-50 mt-1 w-full overflow-hidden rounded-md bg-white py-1 text-base shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none sm:text-sm"
            x-show="open"
        >
            <div class="px-3 py-2 text-sm text-gray-500">
                No proofs found matching "{{ $query }}"
            </div>
        </div>
    @endif
</div>
