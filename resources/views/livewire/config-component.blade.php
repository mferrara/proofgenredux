<div class="px-8 py-4">
    <div class="flex flex-col justify-start gap-y-2">
        <div class="flex flex-row justify-start items-center gap-x-4">
            <div class="text-4xl font-semibold">Application Settings</div>
            <flux:radio.group x-data variant="segmented" x-model="$flux.appearance">
                <flux:radio value="light" icon="sun" />
                <flux:radio value="dark" icon="moon" />
                <flux:radio value="system" icon="computer-desktop" />
            </flux:radio.group>
        </div>

        <div class="mt-6 mb-24">
            <form wire:submit.prevent="save">
            @foreach($configurationsByCategory as $category => $configurations)
                @if($category === 'thumbnails')
                    {{-- Special handling for thumbnails with live preview --}}
                    <div class="mb-8">
                        <div class="text-2xl font-semibold mb-2">
                            {{ $categoryLabels[$category] ?? ucfirst($category) }}
                        </div>
                        
                        @if(!$sampleImagePath)
                            <div class="bg-amber-900/20 border border-amber-600/50 rounded-md p-4 mb-4">
                                <div class="flex items-center gap-2">
                                    <flux:icon name="exclamation-triangle" class="w-5 h-5 text-amber-600" />
                                    <p class="text-amber-200">No sample image found for preview. Add images to storage/sample_images or process some images first.</p>
                                </div>
                            </div>
                        @endif
                        
                        <div class="space-y-6">
                            {{-- Large Thumbnail Settings --}}
                            <div class="bg-zinc-700 rounded-md overflow-hidden">
                                <div class="bg-zinc-800 px-6 py-3">
                                    <h3 class="text-lg font-medium text-gray-200">Large Thumbnails</h3>
                                </div>
                                <div class="p-6">
                                    @if($sampleImagePath && $largeThumbnailPreview)
                                        <div class="mb-6 text-center relative">
                                            <img src="{{ $largeThumbnailPreview }}" 
                                                 alt="Large thumbnail preview" 
                                                 class="inline-block border border-zinc-600 rounded"
                                                 wire:loading.class="opacity-50"
                                                 wire:target="updateThumbnailPreview">
                                            <div wire:loading wire:target="updateThumbnailPreview" class="absolute inset-0 flex items-center justify-center">
                                                <flux:icon.loading class="w-8 h-8 text-blue-500" />
                                            </div>
                                            <div class="mt-2 text-xs text-gray-400">
                                                @php
                                                    $largeWidth = $tempThumbnailValues['thumbnails.large.width'] ?? config('proofgen.thumbnails.large.width');
                                                    $largeHeight = $tempThumbnailValues['thumbnails.large.height'] ?? config('proofgen.thumbnails.large.height');
                                                    $largeQuality = $tempThumbnailValues['thumbnails.large.quality'] ?? config('proofgen.thumbnails.large.quality');
                                                @endphp
                                                Settings: {{ $largeWidth }}×{{ $largeHeight }}px @ {{ $largeQuality }}% quality
                                                @if($largeThumbnailInfo)
                                                    <div class="mt-1">
                                                        Output: {{ $largeThumbnailInfo['dimensions'] }} • {{ $largeThumbnailInfo['size'] }}
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    @endif
                                    
                                    <div class="space-y-4">
                                        @php
                                            $largeConfigs = collect($configurations)->filter(fn($c) => str_starts_with($c->key, 'thumbnails.large.'))->keyBy('key');
                                        @endphp
                                        
                                        {{-- Width and Height on same row --}}
                                        <div class="grid grid-cols-2 gap-4">
                                            @if(isset($largeConfigs['thumbnails.large.width']))
                                                @php $config = $largeConfigs['thumbnails.large.width']; @endphp
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-300 mb-1">
                                                        {{ $config->label ?? $config->key }}
                                                    </label>
                                                    <flux:input
                                                        type="text"
                                                        wire:model.lazy="configValues.{{ $config->id }}"
                                                        x-on:input.debounce.500ms="$wire.updateThumbnailPreview('{{ $config->key }}', $event.target.value)"
                                                        wire:key="{{ $config->key }}-input"
                                                        class="w-full"
                                                        placeholder="e.g. 950"
                                                    />
                                                    @error('configValues.'.$config->id) <div class="text-error text-xs mt-1">{{ $message }}</div> @enderror
                                                </div>
                                            @endif
                                            
                                            @if(isset($largeConfigs['thumbnails.large.height']))
                                                @php $config = $largeConfigs['thumbnails.large.height']; @endphp
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-300 mb-1">
                                                        {{ $config->label ?? $config->key }}
                                                    </label>
                                                    <flux:input
                                                        type="text"
                                                        wire:model.lazy="configValues.{{ $config->id }}"
                                                        x-on:input.debounce.500ms="$wire.updateThumbnailPreview('{{ $config->key }}', $event.target.value)"
                                                        wire:key="{{ $config->key }}-input"
                                                        class="w-full"
                                                        placeholder="e.g. 950"
                                                    />
                                                    @error('configValues.'.$config->id) <div class="text-error text-xs mt-1">{{ $message }}</div> @enderror
                                                </div>
                                            @endif
                                        </div>
                                        
                                        {{-- Quality --}}
                                        @if(isset($largeConfigs['thumbnails.large.quality']))
                                            @php $config = $largeConfigs['thumbnails.large.quality']; @endphp
                                            <div>
                                                <label class="block text-sm font-medium text-gray-300 mb-1">
                                                    {{ $config->label ?? $config->key }}
                                                </label>
                                                <flux:input
                                                    type="text"
                                                    wire:model.lazy="configValues.{{ $config->id }}"
                                                    x-on:input.debounce.500ms="$wire.updateThumbnailPreview('{{ $config->key }}', $event.target.value)"
                                                    wire:key="{{ $config->key }}-input"
                                                    class="w-full"
                                                    placeholder="10-100"
                                                />
                                                <div class="mt-1 text-xs text-gray-500">
                                                    {{ $config->description }} (10-100)
                                                </div>
                                                @error('configValues.'.$config->id) <div class="text-error text-xs mt-1">{{ $message }}</div> @enderror
                                            </div>
                                        @endif
                                        
                                        {{-- Other settings (suffix, font_size, bg_size) --}}
                                        @foreach(['thumbnails.large.suffix', 'thumbnails.large.font_size', 'thumbnails.large.bg_size'] as $key)
                                            @if(isset($largeConfigs[$key]))
                                                @php $config = $largeConfigs[$key]; @endphp
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-300 mb-1">
                                                        {{ $config->label ?? $config->key }}
                                                    </label>
                                                    <flux:input
                                                        type="text"
                                                        wire:model.lazy="configValues.{{ $config->id }}"
                                                        wire:key="{{ $config->key }}-input"
                                                        class="w-full"
                                                    />
                                                    <div class="mt-1 text-xs text-gray-500">
                                                        {{ $config->description }}
                                                    </div>
                                                    @error('configValues.'.$config->id) <div class="text-error text-xs mt-1">{{ $message }}</div> @enderror
                                                </div>
                                            @endif
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                            
                            {{-- Small Thumbnail Settings --}}
                            <div class="bg-zinc-700 rounded-md overflow-hidden">
                                <div class="bg-zinc-800 px-6 py-3">
                                    <h3 class="text-lg font-medium text-gray-200">Small Thumbnails</h3>
                                </div>
                                <div class="p-6">
                                    @if($sampleImagePath && $smallThumbnailPreview)
                                        <div class="mb-6 text-center relative">
                                            <img src="{{ $smallThumbnailPreview }}" 
                                                 alt="Small thumbnail preview" 
                                                 class="inline-block border border-zinc-600 rounded"
                                                 wire:loading.class="opacity-50"
                                                 wire:target="updateThumbnailPreview">
                                            <div wire:loading wire:target="updateThumbnailPreview" class="absolute inset-0 flex items-center justify-center">
                                                <flux:icon.loading class="w-8 h-8 text-blue-500" />
                                            </div>
                                            <div class="mt-2 text-xs text-gray-400">
                                                @php
                                                    $smallWidth = $tempThumbnailValues['thumbnails.small.width'] ?? config('proofgen.thumbnails.small.width');
                                                    $smallHeight = $tempThumbnailValues['thumbnails.small.height'] ?? config('proofgen.thumbnails.small.height');
                                                    $smallQuality = $tempThumbnailValues['thumbnails.small.quality'] ?? config('proofgen.thumbnails.small.quality');
                                                @endphp
                                                Settings: {{ $smallWidth }}×{{ $smallHeight }}px @ {{ $smallQuality }}% quality
                                                @if($smallThumbnailInfo)
                                                    <div class="mt-1">
                                                        Output: {{ $smallThumbnailInfo['dimensions'] }} • {{ $smallThumbnailInfo['size'] }}
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    @endif
                                    
                                    <div class="space-y-4">
                                        @php
                                            $smallConfigs = collect($configurations)->filter(fn($c) => str_starts_with($c->key, 'thumbnails.small.'))->keyBy('key');
                                        @endphp
                                        
                                        {{-- Width and Height on same row --}}
                                        <div class="grid grid-cols-2 gap-4">
                                            @if(isset($smallConfigs['thumbnails.small.width']))
                                                @php $config = $smallConfigs['thumbnails.small.width']; @endphp
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-300 mb-1">
                                                        {{ $config->label ?? $config->key }}
                                                    </label>
                                                    <flux:input
                                                        type="text"
                                                        wire:model.lazy="configValues.{{ $config->id }}"
                                                        x-on:input.debounce.500ms="$wire.updateThumbnailPreview('{{ $config->key }}', $event.target.value)"
                                                        wire:key="{{ $config->key }}-input"
                                                        class="w-full"
                                                        placeholder="e.g. 250"
                                                    />
                                                    @error('configValues.'.$config->id) <div class="text-error text-xs mt-1">{{ $message }}</div> @enderror
                                                </div>
                                            @endif
                                            
                                            @if(isset($smallConfigs['thumbnails.small.height']))
                                                @php $config = $smallConfigs['thumbnails.small.height']; @endphp
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-300 mb-1">
                                                        {{ $config->label ?? $config->key }}
                                                    </label>
                                                    <flux:input
                                                        type="text"
                                                        wire:model.lazy="configValues.{{ $config->id }}"
                                                        x-on:input.debounce.500ms="$wire.updateThumbnailPreview('{{ $config->key }}', $event.target.value)"
                                                        wire:key="{{ $config->key }}-input"
                                                        class="w-full"
                                                        placeholder="e.g. 250"
                                                    />
                                                    @error('configValues.'.$config->id) <div class="text-error text-xs mt-1">{{ $message }}</div> @enderror
                                                </div>
                                            @endif
                                        </div>
                                        
                                        {{-- Quality --}}
                                        @if(isset($smallConfigs['thumbnails.small.quality']))
                                            @php $config = $smallConfigs['thumbnails.small.quality']; @endphp
                                            <div>
                                                <label class="block text-sm font-medium text-gray-300 mb-1">
                                                    {{ $config->label ?? $config->key }}
                                                </label>
                                                <flux:input
                                                    type="text"
                                                    wire:model.lazy="configValues.{{ $config->id }}"
                                                    x-on:input.debounce.500ms="$wire.updateThumbnailPreview('{{ $config->key }}', $event.target.value)"
                                                    wire:key="{{ $config->key }}-input"
                                                    class="w-full"
                                                    placeholder="10-100"
                                                />
                                                <div class="mt-1 text-xs text-gray-500">
                                                    {{ $config->description }} (10-100)
                                                </div>
                                                @error('configValues.'.$config->id) <div class="text-error text-xs mt-1">{{ $message }}</div> @enderror
                                            </div>
                                        @endif
                                        
                                        {{-- Other settings (suffix, font_size, bg_size) --}}
                                        @foreach(['thumbnails.small.suffix', 'thumbnails.small.font_size', 'thumbnails.small.bg_size'] as $key)
                                            @if(isset($smallConfigs[$key]))
                                                @php $config = $smallConfigs[$key]; @endphp
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-300 mb-1">
                                                        {{ $config->label ?? $config->key }}
                                                    </label>
                                                    <flux:input
                                                        type="text"
                                                        wire:model.lazy="configValues.{{ $config->id }}"
                                                        wire:key="{{ $config->key }}-input"
                                                        class="w-full"
                                                    />
                                                    <div class="mt-1 text-xs text-gray-500">
                                                        {{ $config->description }}
                                                    </div>
                                                    @error('configValues.'.$config->id) <div class="text-error text-xs mt-1">{{ $message }}</div> @enderror
                                                </div>
                                            @endif
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @else
                    {{-- Regular table layout for other categories --}}
                    <div class="mb-8">
                        <div class="text-2xl font-semibold mb-2">
                            {{ $categoryLabels[$category] ?? ucfirst($category) }}
                        </div>

                        <div class="bg-zinc-700 rounded-md overflow-hidden">
                            <table class="min-w-full divide-y divide-zinc-600">
                                <thead class="bg-zinc-800">
                                <tr>
                                    <th scope="col" class="w-80 px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                        Setting
                                    </th>
                                    <th scope="col" class="w-120 px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                        Value
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                        Description
                                    </th>
                                </tr>
                                </thead>
                                <tbody class="bg-zinc-700 divide-y divide-zinc-600">
                                @foreach($configurations as $config)
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-200">
                                            {{ $config->label ?? $config->key }}
                                        </div>
                                        <div class="text-xs text-gray-400">
                                            {{ $config->key }}
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        @if($config->is_private)
                                            <div class="text-sm text-gray-400 italic">Hidden</div>
                                        @else
                                            @if($config->type === 'boolean')
                                                <div class="flex flex-row items-center gap-x-2 my-auto">
                                                <flux:switch
                                                    wire:model.live="configValues.{{ $config->id }}"
                                                    wire:key="{{ $config->key }}-switch"
                                                />
                                                @if($config->value)
                                                    <div class="text-sm text-success">
                                                        Enabled
                                                    </div>
                                                @else
                                                    <div class="text-sm text-error">
                                                        Disabled
                                                    </div>
                                                @endif
                                                </div>
                                            @else
                                                <div class="flex flex-row items-center text-sm">
                                                    <flux:input
                                                        type="text"
                                                        class:input="{{ $config->type === 'integer' ? '!w-18 !text-right' : ''  }} {{ $config->type === 'string' ? '!w-32' : '' }}"
                                                        wire:model="configValues.{{ $config->id }}"
                                                        wire:key="{{ $config->key }}-input"
                                                        wire:dirty.class="!border-warning-border !text-warning"
                                                    />
                                                </div>
                                                <div class="@error('configValues.'.$config->id) text-error @endif text-sm mt-1">
                                                    @error('configValues.'.$config->id) {{ $message }} @enderror
                                                </div>
                                            @endif
                                        @endif
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-sm text-gray-300">
                                            {{ $config->description }} ({{ $config->type }})

                                            @if(isset($configSources[$config->id]))
                                                <div class="mt-1 text-xs">
                                                    @if($configSources[$config->id] === 'database_override')
                                                        <span class="text-indigo-400">
                                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                                            </svg>
                                                            Database value overrides .env value
                                                        </span>
                                                    @elseif($configSources[$config->id] === 'same_in_both')
                                                        <span class="text-emerald-400">
                                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                                            </svg>
                                                            Same in database and .env
                                                        </span>
                                                    @elseif($configSources[$config->id] === 'database_only')
                                                        <span class="text-amber-400">
                                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                                            </svg>
                                                            Only in database (not in .env)
                                                        </span>
                                                    @endif
                                                </div>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                @endif
            @endforeach
                <div class="fixed bottom-0 left-0 right-0 bg-zinc-800/90 shadow-lg border-t border-zinc-700 p-4 z-50 transition-all duration-300 ease-in-out transform translate-y-0">
                    <div class="max-w-7xl mx-auto flex justify-between items-center">
                        <div>
                            <!-- Horizon Start/Restart Button -->
                            @if($isHorizonRunning)
                                <flux:button
                                    variant="ghost"
                                    wire:click="restartHorizon"
                                    wire:loading.attr="disabled"
                                    wire:target="restartHorizon"
                                    icon="arrow-path"
                                    class="text-blue-400 hover:text-blue-300"
                                >
                                    Restart Horizon
                                </flux:button>
                            @else
                                <flux:button
                                    variant="ghost"
                                    wire:click="startHorizon"
                                    wire:loading.attr="disabled"
                                    wire:target="startHorizon"
                                    icon="play"
                                    class="text-green-500 hover:text-green-400"
                                >
                                    Start Horizon
                                </flux:button>
                            @endif

                            <div class="inline-flex items-center gap-2 ml-4">
                                <!-- Auto-restart Horizon checkbox -->
                                <flux:checkbox
                                    id="auto_restart_horizon"
                                    wire:model.live="configValues.{{ $this->getConfigId('auto_restart_horizon') }}"
                                    class="text-blue-500"
                                />
                                <label for="auto_restart_horizon" class="text-sm text-gray-300">Auto-restart Horizon when settings change</label>
                            </div>
                        </div>

                        <div class="flex items-center gap-x-4">
                            <flux:button
                                variant="ghost"
                                wire:click="cancel"
                                wire:target="configValues"
                                wire:dirty.class="!bg-zinc-50/10 hover:!bg-warning/20 !text-white"
                                wire:dirty.attr.remove="disabled"
                                disabled
                            >
                                Undo Changes
                            </flux:button>
                            <flux:button
                                wire:click="save"
                                wire:target="configValues"
                                wire:dirty.class="!bg-info/80 hover:!bg-info !text-white"
                                wire:dirty.attr.remove="disabled"
                                disabled
                            >
                                Save Changes
                            </flux:button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
