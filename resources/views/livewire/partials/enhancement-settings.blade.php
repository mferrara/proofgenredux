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
                    {{-- Swift Compatibility Warning --}}
                    @if(PHP_OS_FAMILY === 'Darwin' && !empty($this->swiftCompatibility) && !$this->swiftCompatibility['compatible'])
                        <div class="mb-4 p-4 bg-amber-500/10 border border-amber-500/20 rounded-lg">
                            <h4 class="text-amber-400 font-semibold mb-2">Core Image Enhancement Unavailable</h4>
                            <div class="text-sm text-gray-300">
                                {{ $this->swiftCompatibility['error'] }}
                                
                                @if(!$this->swiftCompatibility['swift_available'])
                                    <div class="mt-2">
                                        <strong>To enable Core Image enhancement:</strong>
                                        <ol class="list-decimal ml-5 mt-1">
                                            <li>Install Xcode Command Line Tools:
                                                <code class="bg-zinc-800 px-2 py-1 rounded text-xs">xcode-select --install</code>
                                            </li>
                                            <li>OR install Xcode from the App Store</li>
                                        </ol>
                                    </div>
                                @elseif($this->swiftCompatibility['version'])
                                    <div class="mt-2">
                                        <strong>Current version:</strong> Swift {{ $this->swiftCompatibility['version'] }}<br>
                                        <strong>Required version:</strong> Swift {{ $this->swiftCompatibility['minimum_version'] }} or higher
                                    </div>
                                @endif
                                
                                <div class="mt-2 text-sm text-gray-400">
                                    Enhancement will fall back to standard image processing.
                                </div>
                            </div>
                        </div>
                    @endif
                    
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
                    
                    {{-- Apply To Options (shown right under main toggle when enabled) --}}
                    @if($configValues[$enabledConfig->id] && count($applyToConfigs) > 0)
                        <div class="mt-6 pl-8">
                            <h4 class="text-sm font-medium text-gray-300 mb-3">Apply Enhancement To:</h4>
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
                </div>
            @endif

            {{-- Enhancement Method Selection (shown when enabled) --}}
            @if($enabledConfig && $configValues[$enabledConfig->id] && $methodConfig)
                <div class="p-6 border-b border-zinc-600">
                    <flux:field>
                        <flux:label>{{ $methodConfig->label }}</flux:label>
                        
                        {{-- Method descriptions (above the select) --}}
                        <div class="mb-3 space-y-2">
                            <div class="p-3 bg-zinc-800 rounded-md text-sm text-gray-400">
                                <strong class="text-gray-300">Adjustable Auto-Levels:</strong> Automatic brightness and contrast adjustment with customizable target levels and clipping points. Best for consistent corrections across image sets.
                            </div>
                            <div class="p-3 bg-zinc-800 rounded-md text-sm text-gray-400">
                                <strong class="text-gray-300">Advanced Tone Mapping:</strong> Sophisticated tone control with percentile clipping, shadow/highlight adjustment, and midtone gamma control. Ideal for images needing targeted adjustments.
                            </div>
                        </div>
                        
                        <flux:select
                            wire:model.defer="configValues.{{ $methodConfig->id }}"
                            wire:key="{{ $methodConfig->key }}-select"
                        >
                            <flux:select.option value="adjustable_auto_levels">Adjustable Auto-Levels</flux:select.option>
                            <flux:select.option value="advanced_tone_mapping">Advanced Tone Mapping</flux:select.option>
                        </flux:select>
                        <flux:description>{{ $methodConfig->description }}</flux:description>
                    </flux:field>
                </div>

                {{-- Advanced Settings (directly below method selection) --}}
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
                            <div class="p-6">
                                {{-- Only show content if a method is selected --}}
                                <div>
                                    {{-- Parameter Descriptions --}}
                                    <div class="mb-6 p-4 bg-zinc-800 rounded-md">
                                        <h5 class="text-sm font-medium text-gray-300 mb-2">Parameter Guide:</h5>
                                        
                                        {{-- Show parameters based on selected method --}}
                                        <div x-show="$wire.configValues[{{ $methodConfig->id }}] === 'adjustable_auto_levels'" x-transition class="space-y-2">
                                            <div class="text-sm text-gray-400">
                                                <strong class="text-gray-300">Target Brightness:</strong> The desired average brightness (0-255). Default 128 is middle gray.
                                            </div>
                                            <div class="text-sm text-gray-400">
                                                <strong class="text-gray-300">Contrast Threshold:</strong> Histogram range below which contrast boost is applied (0-255).
                                            </div>
                                            <div class="text-sm text-gray-400">
                                                <strong class="text-gray-300">Contrast Boost:</strong> Multiplier applied when image needs more contrast (1.0-2.0).
                                            </div>
                                            <div class="text-sm text-gray-400">
                                                <strong class="text-gray-300">Black Point:</strong> Percentage of shadows to clip (0-5%).
                                            </div>
                                            <div class="text-sm text-gray-400">
                                                <strong class="text-gray-300">White Point:</strong> Percentage of highlights to preserve (95-100%).
                                            </div>
                                        </div>
                                        
                                        <div x-show="$wire.configValues[{{ $methodConfig->id }}] === 'advanced_tone_mapping'" x-transition class="space-y-2">
                                            <div class="text-sm text-gray-400">
                                                <strong class="text-gray-300">Percentile Low/High:</strong> Controls which extreme pixels to clip. Lower values preserve more shadows/highlights.
                                            </div>
                                            <div class="text-sm text-gray-400">
                                                <strong class="text-gray-300">Shadow/Highlight Adjustment:</strong> Brightens shadows or darkens highlights (-100 to +100).
                                            </div>
                                            <div class="text-sm text-gray-400">
                                                <strong class="text-gray-300">Shadow/Highlight Radius:</strong> Controls the blend area for adjustments (0-100).
                                            </div>
                                            <div class="text-sm text-gray-400">
                                                <strong class="text-gray-300">Midtone Gamma:</strong> Adjusts midtone brightness. Values < 1.0 darken, > 1.0 brighten.
                                            </div>
                                        </div>
                                    </div>
                                    
                                    {{-- Input Fields --}}
                                    @php
                                        $autoLevelsConfigs = [];
                                        $toneMappingConfigs = [];
                                        
                                        // Define the order we want parameters to appear
                                        $autoLevelsOrder = [
                                            'auto_levels_target_brightness',
                                            'auto_levels_contrast_threshold',
                                            'auto_levels_contrast_boost',
                                            'auto_levels_black_point',
                                            'auto_levels_white_point'
                                        ];
                                        
                                        $toneMappingOrder = [
                                            'tone_mapping_percentile_low',
                                            'tone_mapping_percentile_high',
                                            'tone_mapping_shadow_amount',
                                            'tone_mapping_highlight_amount',
                                            'tone_mapping_shadow_radius',
                                            'tone_mapping_midtone_gamma'
                                        ];
                                        
                                        // Create a map for quick lookup
                                        $configMap = [];
                                        foreach($advancedConfigs as $config) {
                                            $configMap[$config->key] = $config;
                                        }
                                        
                                        // Build ordered arrays
                                        foreach($autoLevelsOrder as $key) {
                                            if (isset($configMap[$key])) {
                                                $autoLevelsConfigs[] = $configMap[$key];
                                            }
                                        }
                                        
                                        foreach($toneMappingOrder as $key) {
                                            if (isset($configMap[$key])) {
                                                $toneMappingConfigs[] = $configMap[$key];
                                            }
                                        }
                                    @endphp
                                    
                                    {{-- Adjustable Auto-Levels Parameters --}}
                                    <div x-show="$wire.configValues[{{ $methodConfig->id }}] === 'adjustable_auto_levels'" x-transition>
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                            @foreach($autoLevelsConfigs as $config)
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
                                    
                                    {{-- Advanced Tone Mapping Parameters --}}
                                    <div x-show="$wire.configValues[{{ $methodConfig->id }}] === 'advanced_tone_mapping'" x-transition>
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                            @foreach($toneMappingConfigs as $config)
                                                <flux:field>
                                                    <flux:label>{{ $config->label }}</flux:label>
                                                    <flux:input
                                                        type="{{ $config->type === 'float' ? 'number' : 'text' }}"
                                                        step="{{ $config->type === 'float' ? '0.1' : '1' }}"
                                                        wire:model.defer="configValues.{{ $config->id }}"
                                                        wire:key="{{ $config->key }}-input"
                                                    />
                                                    <flux:description>
                                                        {{ $config->description }}
                                                        @if(str_contains($config->key, 'percentile_low'))
                                                            <br><span class="text-xs">Lower values preserve more shadow detail</span>
                                                        @elseif(str_contains($config->key, 'percentile_high'))
                                                            <br><span class="text-xs">Higher values preserve more highlight detail</span>
                                                        @endif
                                                    </flux:description>
                                                    @error('configValues.'.$config->id)
                                                        <flux:error>{{ $message }}</flux:error>
                                                    @enderror
                                                </flux:field>
                                            @endforeach
                                        </div>
                                    </div>
                                    
                                    {{-- Show message when no method is selected --}}
                                    <div x-show="!$wire.configValues[{{ $methodConfig->id }}] || ($wire.configValues[{{ $methodConfig->id }}] !== 'adjustable_auto_levels' && $wire.configValues[{{ $methodConfig->id }}] !== 'advanced_tone_mapping')" class="text-sm text-gray-400 text-center py-8">
                                        Please select an enhancement method above to configure its parameters.
                                    </div>
                                </div>{{-- End of wrapper --}}
                            </div>
                        </div>
                    </div>
                @endif
            @endif
        </div>
    </div>
@endif