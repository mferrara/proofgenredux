<div class="px-6 lg:px-10 py-6 max-w-3xl mx-auto">
    <div class="mb-6">
        <flux:heading size="xl" level="1" class="!text-3xl !font-semibold tracking-tight">Server Connection</flux:heading>
        <flux:text class="mt-1">
            Verify the SFTP credentials in
            <flux:link href="{{ route('settings') }}#cat-sftp">Settings → Server (SFTP)</flux:link>
            can reach the configured proofs path.
        </flux:text>
    </div>

    {{-- Current settings (read-only) --}}
    <flux:card class="!p-0 overflow-hidden mb-4">
        <div class="px-5 py-3 border-b border-zinc-200 dark:border-white/10 flex items-center justify-between bg-zinc-50/50 dark:bg-white/[0.02]">
            <flux:heading size="base">Current settings</flux:heading>
            <flux:button
                type="button"
                wire:click="testConnection"
                wire:loading.attr="disabled"
                wire:target="testConnection"
                variant="primary"
                size="sm"
                icon="signal"
            >
                <span wire:loading.remove wire:target="testConnection">Test connection</span>
                <span wire:loading wire:target="testConnection">Testing…</span>
            </flux:button>
        </div>

        <dl class="divide-y divide-zinc-200 dark:divide-white/10">
            <div class="px-5 py-3 grid grid-cols-3 gap-4 items-center">
                <dt class="text-sm font-medium text-zinc-900 dark:text-white">Host</dt>
                <dd class="col-span-2 font-mono text-sm text-zinc-700 dark:text-zinc-300">
                    {{ $host ?: '—' }}<span class="text-zinc-400 dark:text-zinc-500">:{{ $port }}</span>
                </dd>
            </div>
            <div class="px-5 py-3 grid grid-cols-3 gap-4 items-center">
                <dt class="text-sm font-medium text-zinc-900 dark:text-white">Username</dt>
                <dd class="col-span-2 font-mono text-sm text-zinc-700 dark:text-zinc-300">{{ $username ?: '—' }}</dd>
            </div>
            <div class="px-5 py-3 grid grid-cols-3 gap-4 items-start">
                <dt class="text-sm font-medium text-zinc-900 dark:text-white">Key path</dt>
                <dd class="col-span-2 font-mono text-sm text-zinc-700 dark:text-zinc-300 break-all">{{ $key_path ?: '—' }}</dd>
            </div>
            <div class="px-5 py-3 grid grid-cols-3 gap-4 items-start">
                <dt class="text-sm font-medium text-zinc-900 dark:text-white">Proofs path</dt>
                <dd class="col-span-2 font-mono text-sm text-zinc-700 dark:text-zinc-300 break-all">{{ $proofs_path ?: '—' }}</dd>
            </div>
        </dl>
    </flux:card>

    {{-- Test result --}}
    @if($debug_output !== '')
        @if($server_connection_test_result)
            <div class="mb-4 flex gap-3 rounded-lg px-4 py-3
                        bg-emerald-50 dark:bg-emerald-500/10
                        border border-emerald-200/60 dark:border-emerald-500/20">
                <flux:icon name="check-circle" class="size-5 text-emerald-600 dark:text-emerald-400 shrink-0 mt-0.5" />
                <div class="text-sm text-emerald-900 dark:text-emerald-200/90">
                    {{ $debug_output }}
                </div>
            </div>
        @else
            <div class="mb-4 flex gap-3 rounded-lg px-4 py-3
                        bg-rose-50 dark:bg-rose-500/10
                        border border-rose-200/60 dark:border-rose-500/20">
                <flux:icon name="x-circle" class="size-5 text-rose-600 dark:text-rose-400 shrink-0 mt-0.5" />
                <div class="text-sm text-rose-900 dark:text-rose-200/90 break-all">
                    {{ $debug_output }}
                </div>
            </div>
        @endif
    @endif

    {{-- Folders found --}}
    @if($paths_found)
        <flux:card>
            <flux:heading size="base" class="mb-1">Folders found on the server</flux:heading>
            <flux:text class="mb-4">
                Each folder should represent a show. If something here looks unexpected, the proofs path may be misconfigured.
            </flux:text>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                @foreach($paths_found as $path)
                    <div class="flex items-center gap-2 px-3 py-2 rounded-md
                                bg-zinc-50 dark:bg-white/[0.04]
                                border border-zinc-200 dark:border-white/10
                                font-mono text-xs text-zinc-700 dark:text-zinc-300 truncate">
                        <flux:icon name="folder" class="size-3.5 text-amber-500 shrink-0" />
                        <span class="truncate">{{ basename($path) }}</span>
                    </div>
                @endforeach
            </div>
        </flux:card>
    @endif
</div>
