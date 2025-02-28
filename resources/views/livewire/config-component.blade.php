<div class="px-8 py-4">
    <div class="flex flex-col justify-start gap-y-2">
        <div class="flex flex-row justify-start items-center gap-x-4">
            <div class="text-4xl font-semibold">Application Settings</div>
        </div>

        <div class="mt-6">
            @foreach($configurationsByCategory as $category => $configurations)
                <div class="mb-8">
                    <div class="text-2xl font-semibold mb-2">
                        {{ $categoryLabels[$category] ?? ucfirst($category) }}
                    </div>

                    <div class="bg-gray-700 rounded-md overflow-hidden">
                        <table class="min-w-full divide-y divide-gray-600">
                            <thead class="bg-gray-800">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                    Setting
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
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
                                                    wire:model.live="configValues.{{ $config->key }}"
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
                                                <div class="text-sm text-yellow-400">
                                                    {{ $this->getDisplayValue($config->value, $config->type) }}
                                                </div>
                                            @endif
                                        @endif
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-sm text-gray-300">
                                            {{ $config->description }}
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
