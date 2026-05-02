@if(isset($configurationsByCategory['enhancement']))
    <flux:heading size="lg" level="2" class="mb-1">{{ $categoryLabels['enhancement'] ?? 'Image Enhancement' }}</flux:heading>
    <flux:text class="mb-4">Optional auto-correction applied during proof / web / highres image generation.</flux:text>

    @php
        $enhancementConfigs = $configurationsByCategory['enhancement'];
        $enabledConfig = null;
        $methodConfig = null;
        $applyToConfigs = [];
        $advancedConfigs = [];

        foreach($enhancementConfigs as $config) {
            switch($config->key) {
                case 'image_enhancement_enabled':  $enabledConfig = $config; break;
                case 'image_enhancement_method':   $methodConfig = $config; break;
                case 'enhancement_apply_to_proofs':
                case 'enhancement_apply_to_web':
                case 'enhancement_apply_to_highres':
                    $applyToConfigs[] = $config; break;
                default:
                    $advancedConfigs[] = $config;
            }
        }
    @endphp

    <flux:card class="!p-0 overflow-hidden">
        {{-- Master toggle + Apply To --}}
        @if($enabledConfig)
            <div class="px-5 py-5 border-b border-zinc-200 dark:border-white/10">
                {{-- Swift Compatibility Warning --}}
                @if(PHP_OS_FAMILY === 'Darwin' && !empty($this->swiftCompatibility) && !$this->swiftCompatibility['compatible'])
                    <div class="mb-4 flex gap-3 px-4 py-3 rounded-lg
                                bg-amber-50 dark:bg-amber-500/10
                                border border-amber-300/60 dark:border-amber-500/20">
                        <flux:icon name="exclamation-triangle" class="size-5 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
                        <div class="text-sm text-amber-900 dark:text-amber-200/90 space-y-2">
                            <div class="font-medium">Core Image Enhancement Unavailable</div>
                            <div>{{ $this->swiftCompatibility['error'] }}</div>

                            @if(!$this->swiftCompatibility['swift_available'])
                                <div>
                                    <strong>To enable Core Image enhancement:</strong>
                                    <ol class="list-decimal ml-5 mt-1 space-y-0.5">
                                        <li>Install Xcode Command Line Tools: <code class="font-mono text-xs px-1 py-0.5 rounded bg-amber-100 dark:bg-amber-500/20">xcode-select --install</code></li>
                                        <li>OR install Xcode from the App Store</li>
                                    </ol>
                                </div>
                            @elseif($this->swiftCompatibility['version'])
                                <div>
                                    <strong>Current:</strong> Swift {{ $this->swiftCompatibility['version'] }} ·
                                    <strong>Required:</strong> Swift {{ $this->swiftCompatibility['minimum_version'] }} or higher
                                </div>
                            @endif

                            <div class="text-amber-800/80 dark:text-amber-300/70">
                                Enhancement will fall back to standard image processing.
                            </div>
                        </div>
                    </div>
                @endif

                <div class="flex items-start justify-between gap-4">
                    <div>
                        <flux:heading size="base">{{ $enabledConfig->label }}</flux:heading>
                        <flux:text class="mt-1">{{ $enabledConfig->description }}</flux:text>
                    </div>
                    <flux:switch
                        wire:model.defer="configValues.{{ $enabledConfig->id }}"
                        wire:key="{{ $enabledConfig->key }}-switch"
                    />
                </div>

                {{-- Apply To Options --}}
                @if($configValues[$enabledConfig->id] && count($applyToConfigs) > 0)
                    <div class="mt-5 pl-4 border-l-2 border-zinc-200 dark:border-zinc-700">
                        <div class="text-xs uppercase tracking-wider font-medium text-zinc-500 dark:text-zinc-400 mb-3">
                            Apply enhancement to
                        </div>
                        <div class="space-y-3">
                            @foreach($applyToConfigs as $config)
                                <div class="flex items-center justify-between gap-4">
                                    <div>
                                        <div class="text-sm text-zinc-900 dark:text-zinc-200">{{ $config->label }}</div>
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $config->description }}</div>
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

        {{-- Method --}}
        @if($enabledConfig && $configValues[$enabledConfig->id] && $methodConfig)
            <div class="px-5 py-5 border-b border-zinc-200 dark:border-white/10">
                <flux:field>
                    <flux:label>{{ $methodConfig->label }}</flux:label>

                    <div class="mb-3 grid gap-2 sm:grid-cols-2">
                        <div class="px-3 py-2.5 rounded-lg bg-zinc-50 dark:bg-white/[0.04] border border-zinc-200 dark:border-white/10 text-xs">
                            <div class="font-medium text-zinc-900 dark:text-white mb-0.5">Adjustable Auto-Levels</div>
                            <div class="text-zinc-600 dark:text-zinc-400">Automatic brightness/contrast with customizable target levels and clip points. Best for consistent corrections across image sets.</div>
                        </div>
                        <div class="px-3 py-2.5 rounded-lg bg-zinc-50 dark:bg-white/[0.04] border border-zinc-200 dark:border-white/10 text-xs">
                            <div class="font-medium text-zinc-900 dark:text-white mb-0.5">Advanced Tone Mapping</div>
                            <div class="text-zinc-600 dark:text-zinc-400">Percentile clipping, shadow/highlight adjustment, and midtone gamma. Ideal for images needing targeted adjustments.</div>
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

            {{-- Advanced parameters --}}
            @if(count($advancedConfigs) > 0)
                <div x-data="{ expanded: false }">
                    <button
                        @click="expanded = !expanded"
                        class="w-full px-5 py-3.5 flex items-center justify-between
                               text-zinc-900 dark:text-white
                               hover:bg-zinc-50 dark:hover:bg-white/[0.04] transition-colors"
                        type="button"
                    >
                        <span class="font-medium text-sm">Advanced parameters</span>
                        <flux:icon
                            name="chevron-down"
                            class="size-4 text-zinc-500 transition-transform"
                            x-bind:class="{ 'rotate-180': expanded }"
                        />
                    </button>

                    <div x-show="expanded" x-collapse class="border-t border-zinc-200 dark:border-white/10">
                        <div class="px-5 py-5">
                            {{-- Parameter Guide --}}
                            <div class="mb-5 p-4 rounded-lg bg-zinc-50 dark:bg-white/[0.04] border border-zinc-200 dark:border-white/10">
                                <div class="text-xs uppercase tracking-wider font-medium text-zinc-500 dark:text-zinc-400 mb-2">
                                    Parameter guide
                                </div>

                                <div x-show="$wire.configValues[{{ $methodConfig->id }}] === 'adjustable_auto_levels'" x-transition class="space-y-1.5 text-sm">
                                    <div><strong class="text-zinc-900 dark:text-zinc-200">Target Brightness:</strong> <span class="text-zinc-600 dark:text-zinc-400">Desired average brightness (0-255). Default 128 is middle gray.</span></div>
                                    <div><strong class="text-zinc-900 dark:text-zinc-200">Contrast Threshold:</strong> <span class="text-zinc-600 dark:text-zinc-400">Histogram range below which contrast boost is applied (0-255).</span></div>
                                    <div><strong class="text-zinc-900 dark:text-zinc-200">Contrast Boost:</strong> <span class="text-zinc-600 dark:text-zinc-400">Multiplier applied when image needs more contrast (1.0-2.0).</span></div>
                                    <div><strong class="text-zinc-900 dark:text-zinc-200">Black Point:</strong> <span class="text-zinc-600 dark:text-zinc-400">Percentage of shadows to clip (0-5%).</span></div>
                                    <div><strong class="text-zinc-900 dark:text-zinc-200">White Point:</strong> <span class="text-zinc-600 dark:text-zinc-400">Percentage of highlights to preserve (95-100%).</span></div>
                                </div>

                                <div x-show="$wire.configValues[{{ $methodConfig->id }}] === 'advanced_tone_mapping'" x-transition class="space-y-1.5 text-sm">
                                    <div><strong class="text-zinc-900 dark:text-zinc-200">Percentile Low/High:</strong> <span class="text-zinc-600 dark:text-zinc-400">Controls extreme pixel clipping. Lower values preserve more shadows/highlights.</span></div>
                                    <div><strong class="text-zinc-900 dark:text-zinc-200">Shadow/Highlight Adjustment:</strong> <span class="text-zinc-600 dark:text-zinc-400">Brighten shadows or darken highlights (-100 to +100).</span></div>
                                    <div><strong class="text-zinc-900 dark:text-zinc-200">Shadow/Highlight Radius:</strong> <span class="text-zinc-600 dark:text-zinc-400">Blend area for adjustments (0-100).</span></div>
                                    <div><strong class="text-zinc-900 dark:text-zinc-200">Midtone Gamma:</strong> <span class="text-zinc-600 dark:text-zinc-400">Adjusts midtone brightness. &lt; 1.0 darkens, &gt; 1.0 brightens.</span></div>
                                </div>
                            </div>

                            @php
                                $autoLevelsOrder = ['auto_levels_target_brightness','auto_levels_contrast_threshold','auto_levels_contrast_boost','auto_levels_black_point','auto_levels_white_point'];
                                $toneMappingOrder = ['tone_mapping_percentile_low','tone_mapping_percentile_high','tone_mapping_shadow_amount','tone_mapping_highlight_amount','tone_mapping_shadow_radius','tone_mapping_midtone_gamma'];

                                $configMap = [];
                                foreach($advancedConfigs as $config) {
                                    $configMap[$config->key] = $config;
                                }

                                $autoLevelsConfigs = collect($autoLevelsOrder)->map(fn($k) => $configMap[$k] ?? null)->filter();
                                $toneMappingConfigs = collect($toneMappingOrder)->map(fn($k) => $configMap[$k] ?? null)->filter();
                            @endphp

                            <div x-show="$wire.configValues[{{ $methodConfig->id }}] === 'adjustable_auto_levels'" x-transition>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                    @foreach($autoLevelsConfigs as $config)
                                        <flux:field>
                                            <flux:label>{{ $config->label }}</flux:label>
                                            <flux:input
                                                type="{{ $config->type === 'float' ? 'number' : 'text' }}"
                                                step="{{ $config->type === 'float' ? '0.1' : '1' }}"
                                                wire:model.defer="configValues.{{ $config->id }}"
                                                wire:key="{{ $config->key }}-input"
                                                wire:dirty.class="!border-amber-400 dark:!border-amber-500"
                                            />
                                            <flux:description>{{ $config->description }}</flux:description>
                                            @error('configValues.'.$config->id)
                                                <flux:error>{{ $message }}</flux:error>
                                            @enderror
                                        </flux:field>
                                    @endforeach
                                </div>
                            </div>

                            <div x-show="$wire.configValues[{{ $methodConfig->id }}] === 'advanced_tone_mapping'" x-transition>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                    @foreach($toneMappingConfigs as $config)
                                        <flux:field>
                                            <flux:label>{{ $config->label }}</flux:label>
                                            <flux:input
                                                type="{{ $config->type === 'float' ? 'number' : 'text' }}"
                                                step="{{ $config->type === 'float' ? '0.1' : '1' }}"
                                                wire:model.defer="configValues.{{ $config->id }}"
                                                wire:key="{{ $config->key }}-input"
                                                wire:dirty.class="!border-amber-400 dark:!border-amber-500"
                                            />
                                            <flux:description>
                                                {{ $config->description }}
                                                @if(str_contains($config->key, 'percentile_low'))
                                                    <span class="block text-xs mt-0.5">Lower values preserve more shadow detail</span>
                                                @elseif(str_contains($config->key, 'percentile_high'))
                                                    <span class="block text-xs mt-0.5">Higher values preserve more highlight detail</span>
                                                @endif
                                            </flux:description>
                                            @error('configValues.'.$config->id)
                                                <flux:error>{{ $message }}</flux:error>
                                            @enderror
                                        </flux:field>
                                    @endforeach
                                </div>
                            </div>

                            <div x-show="!$wire.configValues[{{ $methodConfig->id }}] || ($wire.configValues[{{ $methodConfig->id }}] !== 'adjustable_auto_levels' && $wire.configValues[{{ $methodConfig->id }}] !== 'advanced_tone_mapping')" class="text-sm text-zinc-500 text-center py-6">
                                Select an enhancement method above to configure its parameters.
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        @endif
    </flux:card>
@endif
