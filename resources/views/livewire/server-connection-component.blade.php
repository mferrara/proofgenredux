<div class="px-8 py-4">

    <div class="mt-4 ml-8 flex flex-col justify-start gap-y-2">
        <div class="flex flex-row justify-start items-center gap-x-4">
            <div class="text-4xl font-semibold">Server Configuration</div>
        </div>

        <div class="mt-4 w-1/3 flex flex-col gap-y-1">
            <div class="flex flex-row justify-between items-center gap-x-4 px-1">
                <div class="text-xl font-semibold text-gray-300">Current Settings</div>
                <flux:button wire:click="testConnection" size="xs">Test Connection</flux:button>
            </div>
            <div class="p-4 bg-gray-600 text-gray-900 rounded-md">
                <div class="grid grid-cols-2 gap-y-1 text-gray-200">
                    <div class="text-lg font-semibold">Server </div><div class="text-right text-yellow-500/80">{{ $host }}</div>
                    <div class="text-lg font-semibold">Port </div><div class="text-right text-yellow-500/80">{{ $port }}</div>
                    <div class="text-lg font-semibold">Username </div><div class="text-right text-yellow-500/80">{{ $username }}</div>
                    <div class="text-lg font-semibold">Proofs Path </div><div>&nbsp;</div>
                    <div class="text-right text-yellow-500/80">{{ $proofs_path }}</div>
                </div>
            </div>
            <div class="flex flex-col gap-y-4">
                <div wire:loading wire:target="testConnection"
                     class="p-4 bg-blue-50 text-blue-900 rounded-md animate-pulse">
                    Running...
                </div>
            </div>
            @if($debug_output !== '')
                <div class="flex flex-col gap-y-2">
                    <div class="p-4 @if($server_connection_test_result) bg-green-50 text-green-900 @else bg-red-50 text-red-900 @endif rounded-md">
                        {{ $debug_output }}
                    </div>
                </div>
            @endif
            @if($paths_found)
                <div class="p-4 bg-green-50 text-green-900 rounded-md">
                    <div class="text-lg font-semibold border-b mb-1">Folders/Shows Found</div>
                    <div class="flex flex-col gap-y-1 text-gray-600">
                        @foreach($paths_found as $path)
                            <div class=""> - {{ $path }}</div>
                        @endforeach
                    </div>
                    <div class="mt-2 px-2 text-green-700">
                        These are the folders that were found on the configured server. Each of these should represent a
                        show or photo shoot of some sort. If that's not the case, something isn't right.
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
