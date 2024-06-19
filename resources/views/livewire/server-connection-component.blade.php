<div class="bg-gray-100 px-8 py-4">

    <div class="mt-4 ml-8 flex flex-col justify-start gap-y-4">
        <div class="flex flex-row justify-start items-center gap-x-4">
            <div class="text-4xl font-semibold">Server Connection</div>
            <button wire:click="testConnection"
                    class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded"
            >
                @if($debug_output !== '')
                    Done
                @else
                    Test
                @endif
            </button>
        </div>
        <div class="flex flex-col gap-y-2">
            @if($debug_output !== '')
                <div class="p-4 bg-yellow-50 text-yellow-900 rounded-md">
                    {{ $debug_output }}
                </div>
            @endif
        </div>
        <div class="flex flex-col gap-y-4">
            <div wire:loading wire:target="testConnection"
                class="p-4 bg-blue-50 text-blue-900 rounded-md animate-pulse">
                Running...
            </div>
        </div>
    </div>
</div>
