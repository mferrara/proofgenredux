@if(isset($configurationsByCategory['enhancement']))
    <div class="mb-8">
        <div class="text-2xl font-semibold mb-2">
            {{ $categoryLabels['enhancement'] ?? 'Image Enhancement' }}
        </div>

        <div class="bg-zinc-700 rounded-lg">
            {{-- Main Enhancement Toggle --}}
            @php
                $enhancementConfigs = $configurationsByCategory['enhancement'];
                $enabledConfig = null;
                $methodConfig = null;
                $applyToConfigs = [];
                $advancedConfigs = [];

                foreach($enhancementConfigs as $config) {
                    switch($config->key) {
                        case 'image_enhancement_enabled':
                            $enabledConfig = $config;
                            break;
                        case 'image_enhancement_method':
                            $methodConfig = $config;
                            break;
                        case 'enhancement_apply_to_proofs':
                        case 'enhancement_apply_to_web':
                        case 'enhancement_apply_to_highres':
                            $applyToConfigs[] = $config;
                            break;
                        default:
                            $advancedConfigs[] = $config;
                    }
                }
            @endphp

            {{-- Main Toggle --}}
            @if($enabledConfig)
                <div class="p-6 border-b border-zinc-600">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-medium text-gray-200">{{ $enabledConfig->label }}</h3>
                            <p class="text-sm text-gray-400 mt-1">{{ $enabledConfig->description }}</p>
                        </div>
                        <flux:switch
                            wire:model.defer="configValues.{{ $enabledConfig->id }}"
                            wire:key="{{ $enabledConfig->key }}-switch"
                        />
                    </div>
                </div>
            @endif

            {{-- Enhancement Settings (shown when enabled) --}}
            @if($enabledConfig && $configValues[$enabledConfig->id])
                {{-- Method Selection --}}
                @if($methodConfig)
                    <div class="p-6 border-b border-zinc-600">
                        <flux:field>
                            <flux:label>{{ $methodConfig->label }}</flux:label>
                            <flux:select
                                wire:model.defer="configValues.{{ $methodConfig->id }}"
                                wire:key="{{ $methodConfig->key }}-select"
                            >
                                <flux:select.option value="basic_auto_levels">Basic Auto-Levels</flux:select.option>
                                <flux:select.option value="percentile_clipping">Percentile Clipping (0.1%-99.9%)</flux:select.option>
                                <flux:select.option value="percentile_with_curve">Percentile Clipping + S-Curve</flux:select.option>
                                <flux:select.option value="clahe">CLAHE (Adaptive Histogram Equalization)</flux:select.option>
                                <flux:select.option value="smart_indoor">Smart Indoor (Optimized for Horse Shows)</flux:select.option>
                            </flux:select>
                            <flux:description>{{ $methodConfig->description }}</flux:description>
                        </flux:field>
                    </div>
                @endif

                {{-- Apply To Options --}}
                @if(count($applyToConfigs) > 0)
                    <div class="p-6 border-b border-zinc-600">
                        <h4 class="text-md font-medium text-gray-200 mb-4">Apply Enhancement To:</h4>
                        <div class="space-y-3">
                            @foreach($applyToConfigs as $config)
                                <div class="flex items-center justify-between">
                                    <div>
                                        <label class="text-sm text-gray-300">{{ $config->label }}</label>
                                        <p class="text-xs text-gray-500">{{ $config->description }}</p>
                                    </div>
                                    <flux:switch
                                        wire:model.defer="configValues.{{ $config->id }}"
                                        wire:key="{{ $config->key }}-switch"
                                    />
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Advanced Settings (Collapsible) --}}
                @if(count($advancedConfigs) > 0)
                    <div x-data="{ expanded: false }">
                        <button
                            @click="expanded = !expanded"
                            class="w-full p-6 flex items-center justify-between hover:bg-zinc-600 transition-colors"
                            type="button"
                        >
                            <h4 class="text-md font-medium text-gray-200">Advanced Settings</h4>
                            <flux:icon
                                name="chevron-down"
                                class="w-5 h-5 text-gray-400 transition-transform"
                                x-bind:class="{ 'rotate-180': expanded }"
                            />
                        </button>

                        <div x-show="expanded" x-collapse class="border-t border-zinc-600">
                            <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                                @foreach($advancedConfigs as $config)
                                    <flux:field>
                                        <flux:label>{{ $config->label }}</flux:label>
                                        <flux:input
                                            type="{{ $config->type === 'float' ? 'number' : 'text' }}"
                                            step="{{ $config->type === 'float' ? '0.1' : '1' }}"
                                            wire:model.defer="configValues.{{ $config->id }}"
                                            wire:key="{{ $config->key }}-input"
                                        />
                                        <flux:description>{{ $config->description }}</flux:description>
                                        @error('configValues.'.$config->id)
                                            <flux:error>{{ $message }}</flux:error>
                                        @enderror
                                    </flux:field>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif
            @endif
        </div>
    </div>
@endif
