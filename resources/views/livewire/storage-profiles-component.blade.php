<div class="px-6 lg:px-10 py-6 max-w-7xl mx-auto">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <a href="{{ route('settings') }}" class="text-xs uppercase tracking-wider text-zinc-500 dark:text-zinc-400 hover:text-zinc-900 dark:hover:text-white">← Settings</a>
            <flux:heading size="xl" level="1" class="!text-3xl !font-semibold tracking-tight">Storage Profiles</flux:heading>
            <flux:text class="mt-1 max-w-2xl">
                Manage where new shows write derived files and which existing shows remain pinned to legacy storage.
            </flux:text>
        </div>
        <flux:button
            type="button"
            variant="primary"
            icon="arrow-path"
            wire:click="refreshFromEnv"
            wire:loading.attr="disabled"
            wire:target="refreshFromEnv"
        >
            <span wire:loading.remove wire:target="refreshFromEnv">Refresh from .env</span>
            <span wire:loading wire:target="refreshFromEnv">Refreshing...</span>
        </flux:button>
    </div>

    <flux:card class="!p-0 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400 border-b border-zinc-200 dark:border-white/10 bg-zinc-50/70 dark:bg-white/[0.02]">
                        <th class="px-5 py-3 font-medium">Profile</th>
                        <th class="px-5 py-3 font-medium">Target</th>
                        <th class="px-5 py-3 font-medium">Status</th>
                        <th class="px-5 py-3 font-medium text-center">Active</th>
                        <th class="px-5 py-3 font-medium text-right">Writable</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                    @forelse($profiles as $profile)
                        @php
                            $profileHealth = $health[$profile->id] ?? ['status' => 'unreachable', 'message' => 'Not checked'];
                        @endphp
                        <tr>
                            <td class="px-5 py-4 align-top">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium text-zinc-900 dark:text-white">{{ $profile->label }}</span>
                                    @if($profile->id === \App\Models\StorageProfile::LEGACY_LOCAL_ID)
                                        <flux:badge color="zinc" size="sm">Legacy</flux:badge>
                                    @endif
                                </div>
                                <div class="mt-1 font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $profile->id }}</div>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <flux:badge color="{{ $profile->driver === 's3' ? 'sky' : 'zinc' }}" size="sm">{{ strtoupper($profile->driver) }}</flux:badge>
                                    <flux:badge color="{{ $profile->is_writable ? 'emerald' : 'zinc' }}" size="sm">{{ $profile->is_writable ? 'Writable' : 'Read-only' }}</flux:badge>
                                    <flux:badge color="{{ $profile->is_active ? 'emerald' : 'zinc' }}" size="sm">{{ $profile->is_active ? 'Active' : 'Pinned only' }}</flux:badge>
                                </div>
                            </td>
                            <td class="px-5 py-4 align-top">
                                <dl class="space-y-1 text-xs">
                                    <div class="grid grid-cols-[4.5rem_1fr] gap-3">
                                        <dt class="text-zinc-500 dark:text-zinc-400">Bucket</dt>
                                        <dd class="font-mono break-all text-zinc-700 dark:text-zinc-300">{{ $profile->bucket ?: '—' }}</dd>
                                    </div>
                                    <div class="grid grid-cols-[4.5rem_1fr] gap-3">
                                        <dt class="text-zinc-500 dark:text-zinc-400">Region</dt>
                                        <dd class="font-mono break-all text-zinc-700 dark:text-zinc-300">{{ $profile->region ?: '—' }}</dd>
                                    </div>
                                    <div class="grid grid-cols-[4.5rem_1fr] gap-3">
                                        <dt class="text-zinc-500 dark:text-zinc-400">Endpoint</dt>
                                        <dd class="font-mono break-all text-zinc-700 dark:text-zinc-300">{{ $profile->endpoint ?: '—' }}</dd>
                                    </div>
                                    <div class="grid grid-cols-[4.5rem_1fr] gap-3">
                                        <dt class="text-zinc-500 dark:text-zinc-400">Root</dt>
                                        <dd class="font-mono break-all text-zinc-700 dark:text-zinc-300">{{ $profile->root ?: '—' }}</dd>
                                    </div>
                                </dl>
                            </td>
                            <td class="px-5 py-4 align-top">
                                <flux:badge color="{{ $this->statusColor($profileHealth['status']) }}" size="sm">
                                    {{ $this->statusLabel($profileHealth['status']) }}
                                </flux:badge>
                                @if($profileHealth['message'])
                                    <div class="mt-2 max-w-xs text-xs text-zinc-500 dark:text-zinc-400 break-words">{{ $profileHealth['message'] }}</div>
                                @endif
                                <div class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ number_format($profile->shows_count) }} {{ \Illuminate\Support\Str::plural('show', $profile->shows_count) }}
                                </div>
                            </td>
                            <td class="px-5 py-4 align-top text-center">
                                <input
                                    type="radio"
                                    name="active_storage_profile"
                                    class="size-4 accent-zinc-900 dark:accent-white disabled:opacity-40"
                                    @checked($profile->is_active)
                                    @disabled(! $profile->is_writable)
                                    wire:click="setActive(@js($profile->id))"
                                    title="{{ $profile->is_writable ? 'Set active profile' : 'Read-only profiles cannot be active' }}"
                                />
                            </td>
                            <td class="px-5 py-4 align-top text-right">
                                <flux:button
                                    type="button"
                                    size="xs"
                                    variant="{{ $profile->is_writable ? 'ghost' : 'primary' }}"
                                    icon="{{ $profile->is_writable ? 'lock-closed' : 'lock-open' }}"
                                    wire:click="confirmWritableToggle(@js($profile->id))"
                                >
                                    {{ $profile->is_writable ? 'Mark read-only' : 'Mark writable' }}
                                </flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-10 text-center text-zinc-500 dark:text-zinc-400">
                                No storage profiles found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-5 py-3 border-t border-zinc-200 dark:border-white/10 bg-zinc-50/70 dark:bg-white/[0.02] text-sm">
            Active profile:
            <span class="font-medium text-zinc-900 dark:text-white">{{ $activeProfile?->label ?? 'None' }}</span>
        </div>
    </flux:card>

    <flux:modal name="confirm-writable-toggle" class="max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Update writable state?</flux:heading>
                <flux:text class="mt-2">
                    @if($pendingWritableProfile)
                        {{ $pendingWritableProfile->label }} will be marked {{ $pendingWritableProfile->is_writable ? 'read-only' : 'writable' }}.
                    @endif
                </flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="toggleWritable">
                    Update profile
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
