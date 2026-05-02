{{--
    Settings card with a grid of fields.

    @props:
        sectionTitle  - section heading
        configs       - iterable of Configuration models
        enabledConfig - optional Configuration for the section's master enable switch
--}}
@props([
    'sectionTitle',
    'configs',
    'enabledConfig' => null,
])

<flux:card class="!p-0 overflow-hidden">
    <div class="px-5 py-3 border-b border-zinc-200 dark:border-white/10 flex items-center justify-between bg-zinc-50/50 dark:bg-white/[0.02]">
        <flux:heading size="base">{{ $sectionTitle }}</flux:heading>
        @if($enabledConfig)
            <div class="flex items-center gap-3">
                <flux:label for="{{ $enabledConfig->key }}-toggle" class="text-sm text-zinc-600 dark:text-zinc-400">
                    {{ $enabledConfig->label ?? 'Enabled' }}
                </flux:label>
                <flux:switch
                    id="{{ $enabledConfig->key }}-toggle"
                    wire:model.defer="configValues.{{ $enabledConfig->id }}"
                    wire:key="{{ $enabledConfig->key }}-switch"
                />
            </div>
        @endif
    </div>

    <div class="p-5">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
            @foreach($configs as $config)
                <flux:field>
                    <flux:label>{{ $config->label ?? $config->key }}</flux:label>
                    <flux:input
                        type="text"
                        wire:model.defer="configValues.{{ $config->id }}"
                        wire:key="{{ $config->key }}-input"
                        wire:dirty.class="!border-amber-400 dark:!border-amber-500"
                        placeholder="{{ str_contains($config->key, 'width') || str_contains($config->key, 'height') ? 'e.g. 1200' : (str_contains($config->key, 'quality') ? '10-100' : '') }}"
                    />
                    @if($config->description)
                        <flux:description>
                            {{ $config->description }}@if(str_contains($config->key, 'quality')) (10–100)@endif
                        </flux:description>
                    @endif
                    @error('configValues.'.$config->id)
                        <flux:error>{{ $message }}</flux:error>
                    @enderror
                </flux:field>
            @endforeach
        </div>
    </div>
</flux:card>
