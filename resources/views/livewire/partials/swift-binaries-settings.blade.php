{{-- Swift Binaries Management Section --}}
@if(PHP_OS_FAMILY === 'Darwin' && !empty($this->swiftCompatibility) && $this->swiftCompatibility['compatible'])
    <flux:heading size="lg" level="2" class="mb-4">Swift Binaries</flux:heading>

    <flux:card>
        <flux:heading size="base" class="mb-3">Status</flux:heading>

        @if(!empty($this->swiftBinariesStatus))
            <div class="space-y-2">
                @foreach($this->swiftBinariesStatus as $name => $status)
                    <div class="flex items-center justify-between gap-4 px-4 py-3 rounded-lg
                                bg-zinc-50 dark:bg-white/[0.04]
                                border border-zinc-200 dark:border-white/10">
                        <div class="flex items-center gap-3 min-w-0">
                            @if($status['exists'] && $status['executable'])
                                <flux:icon.check-circle class="size-5 text-emerald-500 shrink-0" />
                            @else
                                <flux:icon.x-circle class="size-5 text-rose-500 shrink-0" />
                            @endif
                            <div class="min-w-0">
                                <div class="font-medium text-zinc-900 dark:text-white">{{ $name }}</div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400 truncate font-mono">
                                    {{ $status['path'] }}
                                </div>
                            </div>
                        </div>
                        <div class="text-xs text-right shrink-0">
                            @if($status['exists'])
                                <flux:badge color="zinc" size="sm">{{ $status['modified_human'] }}</flux:badge>
                            @else
                                <flux:badge color="amber" size="sm">Not compiled</flux:badge>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <flux:text>No binary status available.</flux:text>
        @endif

        <div class="mt-5 flex flex-wrap items-center gap-3">
            <flux:button
                type="button"
                wire:click="compileSwiftBinaries"
                wire:loading.attr="disabled"
                :disabled="$this->compilingSwiftBinaries"
                variant="primary"
                icon="sparkles"
            >
                <span wire:loading.remove wire:target="compileSwiftBinaries">Compile binaries</span>
                <span wire:loading wire:target="compileSwiftBinaries">Compiling…</span>
            </flux:button>
            <flux:text class="!text-sm">Compile Swift binaries for better enhancement performance.</flux:text>
        </div>

        <div wire:loading wire:target="compileSwiftBinaries" class="mt-4 flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-400">
            <flux:icon.loading class="size-4" />
            <span>Compiling Swift binaries — this may take a moment.</span>
        </div>

        <div class="mt-5 flex gap-3 px-4 py-3 rounded-lg
                    bg-blue-50 dark:bg-blue-500/10
                    border border-blue-200/60 dark:border-blue-500/20">
            <flux:icon.information-circle class="size-5 text-blue-600 dark:text-blue-400 shrink-0 mt-0.5" />
            <div class="text-sm text-blue-900 dark:text-blue-200/90 space-y-1.5">
                <p>Swift binaries are automatically compiled during updates. Use this to recompile manually.</p>
                <p>After compiling, the Core Image daemon needs to be restarted to use the new binaries.</p>
            </div>
        </div>
    </flux:card>

    {{-- Daemon restart confirmation --}}
    <flux:modal name="swift-restart-daemon" class="md:w-96">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Restart Core Image Daemon?</flux:heading>
                <flux:text class="mt-2">
                    The Swift binaries have been compiled successfully. The Core Image daemon needs to be restarted to use the new binaries.
                </flux:text>
                <flux:text class="mt-3 text-sm">
                    This will briefly interrupt any ongoing image enhancement operations.
                </flux:text>
            </div>

            <div class="flex gap-2 justify-end">
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
