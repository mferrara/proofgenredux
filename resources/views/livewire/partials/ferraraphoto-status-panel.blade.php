@php
    /** @var array|null $ferraraphoto_status From FerraraphotoTargetVerifier::verifyShow/Class */
    /** @var \App\Models\Show|null $show_for_slug Pass the Show model to render the slug-override row (only on show view) */
    $checkAction ??= 'checkFerraraphotoStatus';
    $show_for_slug ??= null;
@endphp

<div class="rounded border border-zinc-200 bg-zinc-50 p-3 text-sm dark:border-zinc-700 dark:bg-zinc-800/50">
    <div class="flex items-center justify-between gap-2">
        <div class="font-medium text-zinc-700 dark:text-zinc-200">Ferraraphoto target</div>
        <flux:button size="xs" variant="ghost" icon="arrow-path" wire:click="{{ $checkAction }}"
            wire:loading.attr="disabled" wire:target="{{ $checkAction }}">
            {{ $ferraraphoto_status ? 'Re-check' : 'Check' }}
        </flux:button>
    </div>

    @if ($show_for_slug)
        @php
            $effectiveSlug = $show_for_slug->ferraraphoto_slug;
            $hasOverride = ! empty($show_for_slug->ferraraphoto_show_slug);
        @endphp
        <div class="mt-2 mb-2 pb-2 border-b border-zinc-200 dark:border-zinc-700">
            @if ($editingFerraraphotoSlug ?? false)
                <div class="flex items-stretch gap-2">
                    <flux:input
                        type="text"
                        wire:model.defer="ferraraphotoSlugDraft"
                        placeholder="{{ $show_for_slug->id }}"
                        class="font-mono !text-sm flex-1"
                        size="sm"
                    />
                    <flux:button size="xs" variant="primary" wire:click="saveFerraraphotoSlug">Save</flux:button>
                    <flux:button size="xs" variant="ghost" wire:click="cancelEditingFerraraphotoSlug">Cancel</flux:button>
                </div>
                <flux:text class="!text-xs mt-1 text-zinc-500 dark:text-zinc-400">
                    Leave blank to use the proofgen show id (<code class="font-mono">{{ $show_for_slug->id }}</code>).
                </flux:text>
            @else
                <div class="flex items-baseline justify-between gap-2">
                    <div class="flex items-baseline gap-2 min-w-0">
                        <span class="text-zinc-500 dark:text-zinc-400 text-xs whitespace-nowrap">Slug on ferraraphoto:</span>
                        <code class="font-mono text-zinc-700 dark:text-zinc-200 truncate">{{ $effectiveSlug }}</code>
                        @if ($hasOverride)
                            <flux:badge color="amber" size="sm">override</flux:badge>
                        @endif
                    </div>
                    <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="startEditingFerraraphotoSlug" title="Override the ferraraphoto slug if it differs from the proofgen show id">Edit</flux:button>
                </div>
            @endif
        </div>
    @endif

    @if ($ferraraphoto_status)
        <dl class="mt-2 grid grid-cols-1 gap-1 sm:grid-cols-3">
            @foreach (['proofs' => 'Proofs', 'web_images' => 'Web', 'highres_images' => 'Highres'] as $key => $label)
                @php $entry = $ferraraphoto_status[$key]; @endphp
                <div class="flex items-baseline justify-between gap-2">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                    <dd class="font-mono text-xs">
                        @if ($entry['error'])
                            <flux:badge color="rose" size="sm" title="{{ $entry['error'] }}">unreachable</flux:badge>
                        @elseif ($entry['exists'])
                            <flux:badge color="emerald" size="sm">ready</flux:badge>
                        @else
                            <flux:badge color="amber" size="sm" title="Directory does not exist on remote yet — uploads will create it but the ferraraphoto admin may need to import the show after first upload.">missing</flux:badge>
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>
        @if (! $ferraraphoto_status['all_exist'] && ! $ferraraphoto_status['any_errored'])
            <flux:text class="!text-xs mt-2 text-amber-600 dark:text-amber-400">
                One or more remote directories don't exist yet. Uploads will still proceed and create them, but the
                ferraraphoto admin won't see this show in the public site until they run "Import Classes" for it.
            </flux:text>
        @elseif ($ferraraphoto_status['any_errored'])
            <flux:text class="!text-xs mt-2 text-rose-600 dark:text-rose-400">
                Couldn't reach the remote ferraraphoto host — check Settings → Legacy SFTP.
            </flux:text>
        @endif
    @else
        <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
            Confirms the remote ferraraphoto disks have a directory for this show/class. Cheap in local-driver dev mode;
            one SSH round-trip per disk in SFTP mode.
        </div>
    @endif
</div>
