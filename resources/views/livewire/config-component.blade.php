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
            <form wire:submit="save">
            @foreach($configurationsByCategory as $category => $configurations)
                @if($category === 'thumbnails')
                    {{-- Special handling for thumbnails with live preview --}}
                    <div class="mb-8" x-data="{ activeTab: 'large' }">
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

                        {{-- Tab Navigation --}}
                        <div class="mb-6">
                            <flux:tabs variant="segmented">
                                <flux:tab
                                    name="large"
                                    x-on:click="activeTab = 'large'"
                                    x-bind:selected="activeTab === 'large'"
                                >
                                    Large Thumbnails
                                </flux:tab>
                                <flux:tab
                                    name="small"
                                    x-on:click="activeTab = 'small'"
                                    x-bind:selected="activeTab === 'small'"
                                >
                                    Small Thumbnails
                                </flux:tab>
                            </flux:tabs>
                        </div>

                        <div class="space-y-6">
                            @php
                                $largeConfigs = collect($configurations)->filter(fn($c) => str_starts_with($c->key, 'thumbnails.large.'))->keyBy('key');
                                $smallConfigs = collect($configurations)->filter(fn($c) => str_starts_with($c->key, 'thumbnails.small.'))->keyBy('key');
                            @endphp

                            {{-- Large Thumbnail Tab Content --}}
                            <div x-show="activeTab === 'large'" x-transition>
                                {{-- Large Preview Section --}}
                                @if($sampleImagePath)
                                    <div class="bg-zinc-800 rounded-md p-6 mb-6">
                                        <h3 class="text-lg font-medium text-gray-200 mb-4">Large Thumbnail Preview</h3>
                                        @if($largeThumbnailPreview)
                                            <div class="relative inline-block">
                                                <img src="{{ $largeThumbnailPreview }}"
                                                     alt="Large thumbnail preview"
                                                     class="border border-zinc-600 rounded"
                                                     wire:loading.class="opacity-50"
                                                     wire:target="updatePreview">
                                                <div wire:loading.delay wire:target="updatePreview"
                                                     class="absolute inset-0 flex items-center justify-center">
                                                    <flux:icon.loading class="w-8 h-8 text-blue-500" />
                                                </div>
                                            </div>
                                            @if($largeThumbnailInfo)
                                                <div class="mt-2 text-xs text-gray-400">
                                                    Output: {{ $largeThumbnailInfo['dimensions'] }} • {{ $largeThumbnailInfo['size'] }}
                                                </div>
                                            @endif
                                        @else
                                            <div class="w-[950px] h-[950px] bg-zinc-700 border border-zinc-600 rounded flex items-center justify-center">
                                                <flux:icon.loading class="w-8 h-8 text-gray-500" />
                                            </div>
                                        @endif

                                        {{-- Large Preview Controls --}}
                                        <div class="mt-4 flex items-end gap-4">
                                            <div>
                                                <label class="block text-xs text-gray-400 mb-1">Width</label>
                                                <flux:input
                                                    type="text"
                                                    wire:model="tempThumbnailValues.thumbnails.large.width"
                                                    wire:change="updatePreview"
                                                    class="w-24"
                                                />
                                            </div>
                                            <div>
                                                <label class="block text-xs text-gray-400 mb-1">Height</label>
                                                <flux:input
                                                    type="text"
                                                    wire:model="tempThumbnailValues.thumbnails.large.height"
                                                    wire:change="updatePreview"
                                                    class="w-24"
                                                />
                                            </div>
                                            <div>
                                                <label class="block text-xs text-gray-400 mb-1">Quality (10-100)</label>
                                                <flux:input
                                                    type="text"
                                                    wire:model="tempThumbnailValues.thumbnails.large.quality"
                                                    wire:change="updatePreview"
                                                    class="w-24"
                                                />
                                            </div>
                                        </div>
                                    </div>
                                @endif

                                {{-- Large Thumbnail Settings --}}
                                <div class="bg-zinc-700 rounded-md overflow-hidden">
                                    <div class="bg-zinc-800 px-6 py-3">
                                        <h3 class="text-lg font-medium text-gray-200">Large Thumbnail Settings</h3>
                                    </div>
                                    <div class="p-6">
                                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                                            @foreach($largeConfigs as $config)
                                                <flux:field>
                                                    <flux:label>{{ $config->label ?? $config->key }}</flux:label>
                                                    <flux:input
                                                        type="text"
                                                        wire:model.defer="configValues.{{ $config->id }}"
                                                        wire:key="{{ $config->key }}-input"
                                                        placeholder="{{ str_contains($config->key, 'width') || str_contains($config->key, 'height') ? 'e.g. 950' : (str_contains($config->key, 'quality') ? '10-100' : '') }}"
                                                    />
                                                    @if($config->description)
                                                        <flux:description>
                                                            {{ $config->description }}
                                                            @if(str_contains($config->key, 'quality')) (10-100) @endif
                                                        </flux:description>
                                                    @endif
                                                    @error('configValues.'.$config->id)
                                                        <flux:error>{{ $message }}</flux:error>
                                                    @enderror
                                                </flux:field>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Small Thumbnail Tab Content --}}
                            <div x-show="activeTab === 'small'" x-transition>
                                {{-- Small Preview Section --}}
                                @if($sampleImagePath)
                                    <div class="bg-zinc-800 rounded-md p-6 mb-6">
                                        <h3 class="text-lg font-medium text-gray-200 mb-4">Small Thumbnail Preview</h3>
                                        @if($smallThumbnailPreview)
                                            <div class="relative inline-block">
                                                <img src="{{ $smallThumbnailPreview }}"
                                                     alt="Small thumbnail preview"
                                                     class="border border-zinc-600 rounded"
                                                     wire:loading.class="opacity-50"
                                                     wire:target="updatePreview">
                                                <div wire:loading.delay wire:target="updatePreview"
                                                     class="absolute inset-0 flex items-center justify-center">
                                                    <flux:icon.loading class="w-8 h-8 text-blue-500" />
                                                </div>
                                            </div>
                                            @if($smallThumbnailInfo)
                                                <div class="mt-2 text-xs text-gray-400">
                                                    Output: {{ $smallThumbnailInfo['dimensions'] }} • {{ $smallThumbnailInfo['size'] }}
                                                </div>
                                            @endif
                                        @else
                                            <div class="w-[250px] h-[250px] bg-zinc-700 border border-zinc-600 rounded flex items-center justify-center">
                                                <flux:icon.loading class="w-8 h-8 text-gray-500" />
                                            </div>
                                        @endif

                                        {{-- Small Preview Controls --}}
                                        <div class="mt-4 flex items-end gap-4">
                                            <div>
                                                <label class="block text-xs text-gray-400 mb-1">Width</label>
                                                <flux:input
                                                    type="text"
                                                    wire:model="tempThumbnailValues.thumbnails.small.width"
                                                    wire:change="updatePreview"
                                                    class="w-24"
                                                />
                                            </div>
                                            <div>
                                                <label class="block text-xs text-gray-400 mb-1">Height</label>
                                                <flux:input
                                                    type="text"
                                                    wire:model="tempThumbnailValues.thumbnails.small.height"
                                                    wire:change="updatePreview"
                                                    class="w-24"
                                                />
                                            </div>
                                            <div>
                                                <label class="block text-xs text-gray-400 mb-1">Quality (10-100)</label>
                                                <flux:input
                                                    type="text"
                                                    wire:model="tempThumbnailValues.thumbnails.small.quality"
                                                    wire:change="updatePreview"
                                                    class="w-24"
                                                />
                                            </div>
                                        </div>
                                    </div>
                                @endif

                                {{-- Small Thumbnail Settings --}}
                                <div class="bg-zinc-700 rounded-md overflow-hidden">
                                    <div class="bg-zinc-800 px-6 py-3">
                                        <h3 class="text-lg font-medium text-gray-200">Small Thumbnail Settings</h3>
                                    </div>
                                    <div class="p-6">
                                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                                            @foreach($smallConfigs as $config)
                                                <flux:field>
                                                    <flux:label>{{ $config->label ?? $config->key }}</flux:label>
                                                    <flux:input
                                                        type="text"
                                                        wire:model.defer="configValues.{{ $config->id }}"
                                                        wire:key="{{ $config->key }}-input"
                                                        placeholder="{{ str_contains($config->key, 'width') || str_contains($config->key, 'height') ? 'e.g. 250' : (str_contains($config->key, 'quality') ? '10-100' : '') }}"
                                                    />
                                                    @if($config->description)
                                                        <flux:description>
                                                            {{ $config->description }}
                                                            @if(str_contains($config->key, 'quality')) (10-100) @endif
                                                        </flux:description>
                                                    @endif
                                                    @error('configValues.'.$config->id)
                                                        <flux:error>{{ $message }}</flux:error>
                                                    @enderror
                                                </flux:field>
                                            @endforeach
                                        </div>
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
                                                        class:input="{{ $config->type === 'integer' ? '!w-18 !text-right' : ''  }} {{ ($config->type === 'string') ? '!w-28' : '' }}"
                                                        wire:model.defer="configValues.{{ $config->id }}"
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
                    <div class="max-w-7xl mx-auto">
                        <!-- Horizon Process Info -->
                        @if($isHorizonRunning && isset($horizonProcessInfo['main_process']))
                            <div class="mb-3 text-xs text-gray-400">
                                <div class="flex gap-4">
                                    <span>PID: {{ $horizonProcessInfo['main_process']['pid'] ?? 'N/A' }}</span>
                                    <span>CPU: {{ $horizonProcessInfo['main_process']['cpu'] ?? 'N/A' }}%</span>
                                    <span>Memory: {{ $horizonProcessInfo['main_process']['memory'] ?? 'N/A' }}%</span>
                                    <span>Supervisors: {{ $horizonProcessInfo['supervisor_count'] ?? 0 }}</span>
                                    <span>Workers: {{ $horizonProcessInfo['worker_count'] ?? 0 }}</span>
                                    <span>Total Processes: {{ $horizonProcessInfo['total_processes'] ?? 0 }}</span>
                                </div>
                            </div>
                        @endif
                        
                        <div class="flex justify-between items-center">
                            <div class="flex items-center gap-2">
                                <!-- Update Status -->
                                <div class="flex items-center gap-3 mr-6 px-4 py-2 bg-zinc-700/50 rounded-md">
                                    @if($checkingForUpdates)
                                        <flux:icon.loading class="w-4 h-4 text-blue-400" />
                                        <span class="text-sm text-gray-400">Checking for updates...</span>
                                    @elseif($updateInfo)
                                        @if($updateInfo['update_available'])
                                            <flux:icon name="arrow-down-circle" class="w-4 h-4 text-amber-400" />
                                            <span class="text-sm text-gray-300">
                                                Update available: 
                                                <span class="text-amber-400">{{ $updateInfo['latest_version'] }}</span>
                                            </span>
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                wire:click="performUpdate"
                                                wire:loading.attr="disabled"
                                                wire:target="performUpdate"
                                                class="text-amber-400 hover:text-amber-300"
                                            >
                                                Update Now
                                            </flux:button>
                                        @else
                                            <flux:icon name="check-circle" class="w-4 h-4 text-emerald-400" />
                                            <span class="text-sm text-gray-400">
                                                Up to date 
                                                <span class="text-emerald-400">{{ $updateInfo['current_version'] }}</span>
                                            </span>
                                        @endif
                                    @endif
                                </div>
                                
                                <div class="w-px h-8 bg-zinc-600"></div>
                                
                                <!-- Horizon Control Buttons -->
                                @if($isHorizonRunning)
                                    <flux:button
                                        variant="ghost"
                                        wire:click="stopHorizon"
                                        wire:loading.attr="disabled"
                                        wire:target="stopHorizon"
                                        icon="stop"
                                        class="text-red-500 hover:text-red-400"
                                    >
                                        Stop
                                    </flux:button>
                                    
                                    <flux:button
                                        variant="ghost"
                                        wire:click="restartHorizon"
                                        wire:loading.attr="disabled"
                                        wire:target="restartHorizon"
                                        icon="arrow-path"
                                        class="text-blue-400 hover:text-blue-300"
                                    >
                                        Restart
                                    </flux:button>
                                    
                                    <flux:dropdown align="top">
                                        <flux:button 
                                            variant="ghost" 
                                            icon="exclamation-triangle"
                                            class="text-amber-500 hover:text-amber-400"
                                        >
                                            Force Actions
                                        </flux:button>
                                        
                                        <flux:menu>
                                            <flux:menu.item 
                                                wire:click="forceKillHorizon"
                                                wire:confirm="Are you sure you want to force kill all Horizon processes? This should only be used if normal stop doesn't work."
                                                icon="x-circle"
                                                variant="danger"
                                            >
                                                Force Kill All Processes
                                            </flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
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
    
    <!-- Update Progress Modal -->
    <flux:modal name="update-progress" class="max-w-2xl">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Application Update in Progress</flux:heading>
            </div>
            
            <div>
                @if($performingUpdate)
                    <div class="flex items-center gap-3 mb-4">
                        <flux:icon.loading class="w-5 h-5 text-blue-500" />
                        <span class="text-gray-300">Updating application...</span>
                    </div>
                @endif
                
                <div class="bg-zinc-800 rounded-md p-4 max-h-96 overflow-y-auto">
                    <pre class="text-xs text-gray-400 whitespace-pre-wrap">@foreach($updateSteps as $step){{ $step }}
@endforeach</pre>
                </div>
            </div>
            
            @if(!$performingUpdate)
                <div class="flex gap-2">
                    <flux:spacer />
                    <flux:modal.close>
                        <flux:button variant="primary">Close</flux:button>
                    </flux:modal.close>
                </div>
            @endif
        </div>
    </flux:modal>
    
    <!-- Rollback Instructions Modal -->
    <flux:modal name="rollback-instructions" class="max-w-2xl">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Update Failed - Rollback Instructions</flux:heading>
                <flux:text class="mt-2">
                    The update failed, but a backup was created. To rollback to the previous version:
                </flux:text>
            </div>
            
            <ol class="list-decimal list-inside space-y-2 text-sm text-gray-400">
                <li>Navigate to your application directory</li>
                <li>Delete all contents EXCEPT the <code class="bg-zinc-800 px-1 py-0.5 rounded">/backups</code> directory</li>
                <li>Copy the contents of the most recent backup folder back to the application directory</li>
                <li>Restart the application</li>
            </ol>
            
            @php
                $backups = $this->getBackups();
            @endphp
            
            @if(count($backups) > 0)
                <div>
                    <h4 class="text-sm font-medium text-gray-300 mb-2">Available Backups:</h4>
                    <div class="bg-zinc-800 rounded-md p-3 space-y-1">
                        @foreach($backups as $backup)
                            <div class="text-xs text-gray-400">
                                <span class="text-gray-300">{{ $backup['name'] }}</span> - 
                                {{ $backup['date'] }} ({{ $backup['size'] }})
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
            
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="primary">Close</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
</div>

<script>
    document.addEventListener('livewire:initialized', () => {
        Livewire.on('reload-page-delayed', () => {
            setTimeout(() => {
                window.location.reload();
            }, 5000);
        });
    });
</script>
