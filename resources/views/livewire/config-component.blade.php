<div class="px-8 py-4">
    <div class="flex flex-col justify-start gap-y-2">
        <div class="flex flex-row justify-start items-center gap-x-4">
            <div class="text-4xl font-semibold">Application Settings</div>
        </div>

        <div class="mt-6 mb-24">
            <form wire:submit="save">
            @foreach($configurationsByCategory as $category => $configurations)
                <div class="mb-8">
                    <div class="text-2xl font-semibold mb-2">
                        {{ $categoryLabels[$category] ?? ucfirst($category) }}
                    </div>

                    <div class="bg-gray-700 rounded-md overflow-hidden">
                        <table class="min-w-full divide-y divide-gray-600">
                            <thead class="bg-gray-800">
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
                            <tbody class="bg-gray-700 divide-y divide-gray-600">
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
                                                        <span class="text-blue-400">
                                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                                            </svg>
                                                            Database value overrides .env value
                                                        </span>
                                                    @elseif($configSources[$config->id] === 'same_in_both')
                                                        <span class="text-green-400">
                                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                                            </svg>
                                                            Same in database and .env
                                                        </span>
                                                    @elseif($configSources[$config->id] === 'database_only')
                                                        <span class="text-yellow-400">
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
            @endforeach
                <div class="fixed bottom-0 left-0 right-0 bg-gray-800/90 shadow-lg border-t border-gray-700 p-4 z-50 transition-all duration-300 ease-in-out transform translate-y-0">
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
                                wire:dirty.class="!bg-gray-50/10 hover:!bg-warning/20 !text-white"
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
