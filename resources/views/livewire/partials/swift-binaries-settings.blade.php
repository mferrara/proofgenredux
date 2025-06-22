{{-- Swift Binaries Management Section --}}
@if(PHP_OS_FAMILY === 'Darwin' && !empty($this->swiftCompatibility) && $this->swiftCompatibility['compatible'])
    <div class="mb-8">
        <div class="text-2xl font-semibold mb-2">
            Swift Binary Management
        </div>

        <div class="bg-zinc-700 rounded-lg p-6">
            {{-- Binaries Status --}}
            <div class="mb-6">
                <h3 class="text-lg font-medium text-gray-200 mb-4">Swift Binaries Status</h3>

                @if(!empty($this->swiftBinariesStatus))
                    <div class="space-y-3">
                        @foreach($this->swiftBinariesStatus as $name => $status)
                            <div class="flex items-center justify-between p-4 bg-zinc-800 rounded-lg">
                                <div class="flex-1">
                                    <div class="flex items-center gap-3">
                                        @if($status['exists'] && $status['executable'])
                                            <flux:icon.check-circle class="w-5 h-5 text-green-500" />
                                        @else
                                            <flux:icon.x-circle class="w-5 h-5 text-red-500" />
                                        @endif
                                        <span class="font-medium text-gray-200">{{ $name }}</span>
                                    </div>
                                    <div class="mt-1 text-sm text-gray-400">
                                        Path: {{ $status['path'] }}
                                        @if($status['exists'])
                                            <span class="ml-2">• Modified: {{ $status['modified_human'] }}</span>
                                        @else
                                            <span class="ml-2 text-amber-400">• Not compiled</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-gray-400">No binary status available.</p>
                @endif
            </div>

            {{-- Actions --}}
            <div class="flex flex-col sm:flex-row sm:items-center gap-4">
                <flux:button
                    wire:click="compileSwiftBinaries"
                    wire:loading.attr="disabled"
                    :disabled="$this->compilingSwiftBinaries"
                    variant="primary"
                    icon="sparkles"
                >
                    <span wire:loading.remove wire:target="compileSwiftBinaries">Compile Swift Binaries</span>
                    <span wire:loading wire:target="compileSwiftBinaries">Compiling...</span>
                </flux:button>

                <div class="text-sm text-gray-400">
                    Compile Swift binaries for better performance
                </div>
            </div>

            {{-- Compilation Progress --}}
            <div wire:loading wire:target="compileSwiftBinaries" class="mt-4">
                <div class="flex items-center gap-3">
                    <flux:icon.loading class="w-5 h-5 text-blue-500" />
                    <span class="text-gray-300">Compiling Swift binaries, this may take a moment...</span>
                </div>
            </div>

            {{-- Info Box --}}
            <div class="mt-6 p-4 bg-blue-500/10 border border-blue-500/20 rounded-lg">
                <div class="flex gap-3">
                    <flux:icon.information-circle class="w-5 h-5 text-blue-400 flex-shrink-0 mt-0.5" />
                    <div class="text-sm text-gray-300">
                        <p class="mb-2">
                            Swift binaries are automatically compiled during updates. Use this button to manually recompile if needed.
                        </p>
                        <p>
                            After compiling, the Core Image daemon will need to be restarted to use the new binaries.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Modal for daemon restart confirmation --}}
    <flux:modal name="swift-restart-daemon" class="md:w-96">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Restart Core Image Daemon?</flux:heading>
                <flux:text class="mt-2">
                    The Swift binaries have been compiled successfully. The Core Image daemon needs to be restarted to use the new binaries.
                </flux:text>
                <flux:text class="mt-3 text-sm text-gray-300">
                    This will briefly interrupt any ongoing image enhancement operations.
                </flux:text>
            </div>

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button
                    variant="primary"
                    wire:click="restartCoreImageDaemon"
                    x-on:click="$flux.modal('swift-restart-daemon').close()"
                    icon="arrow-path"
                >
                    Restart Daemon
                </flux:button>
            </div>
        </div>
    </flux:modal>
@endif
