@php
    /** @var array|null $ferraraphoto_status From FerraraphotoTargetVerifier::verifyShow/Class */
    /** @var \App\Models\Show|null $show_for_slug Pass the Show model to render the slug-override row (only on show view) */
    /** @var \App\Services\Delivery\DeliveryTarget|null $delivery_target Where uploads go right now (show view only) */
    /** @var \App\Services\Delivery\DeliveryTarget|null $delivery_target_pending Destination Gallery reported that is waiting for acceptance */
    $checkAction ??= 'checkFerraraphotoStatus';
    $show_for_slug ??= null;
    $delivery_target ??= null;
    $delivery_target_pending ??= null;
    $deliveryKinds = ['proofs' => 'Proofs', 'web_images' => 'Web', 'highres_images' => 'Highres'];
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

    @if ($delivery_target)
        <div class="mt-2 mb-2 pb-2 border-b border-zinc-200 dark:border-zinc-700">
            <div class="flex items-baseline gap-2">
                <span class="text-zinc-500 dark:text-zinc-400 text-xs whitespace-nowrap">Uploads go to:</span>
                @if ($delivery_target->usesSsh())
                    <code class="font-mono text-xs text-zinc-700 dark:text-zinc-200 truncate">{{ $delivery_target->username }}{{ '@' }}{{ $delivery_target->host ?: '(no host)' }}</code>
                @else
                    <code class="font-mono text-xs text-zinc-700 dark:text-zinc-200">this Mac</code>
                @endif
                @if ($delivery_target->isFromGallery())
                    <flux:badge color="emerald" size="sm" title="Destination provided by the website. Nothing to configure here.">from website</flux:badge>
                @else
                    <flux:badge color="zinc" size="sm" title="Using this computer's Legacy SFTP settings: the website did not provide a destination (older site version, no API token, or local driver).">local settings</flux:badge>
                @endif
            </div>
            <dl class="mt-1 space-y-0.5">
                @foreach ($deliveryKinds as $key => $label)
                    <div class="flex items-baseline gap-2">
                        <dt class="w-14 shrink-0 text-xs text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                        <dd class="font-mono text-xs text-zinc-700 dark:text-zinc-200 truncate" title="{{ $delivery_target->directory($key) }}">{{ $delivery_target->directory($key) ?: 'not configured' }}</dd>
                    </div>
                @endforeach
            </dl>

            @if ($delivery_target_pending)
                <div class="mt-2 rounded border border-amber-300 bg-amber-50 p-2 dark:border-amber-600/60 dark:bg-amber-950/30">
                    <div class="text-xs font-medium text-amber-800 dark:text-amber-200">
                        Uploads are stopped: the destination changed after this show already uploaded files.
                    </div>
                    <dl class="mt-1 space-y-0.5">
                        <div class="flex items-baseline gap-2">
                            <dt class="w-14 shrink-0 text-xs text-amber-700 dark:text-amber-300">Host</dt>
                            <dd class="font-mono text-xs text-amber-900 dark:text-amber-100 truncate">{{ $delivery_target_pending->usesSsh() ? $delivery_target_pending->username.'@'.$delivery_target_pending->host : 'this Mac' }}</dd>
                        </div>
                        @foreach ($deliveryKinds as $key => $label)
                            <div class="flex items-baseline gap-2">
                                <dt class="w-14 shrink-0 text-xs text-amber-700 dark:text-amber-300">{{ $label }}</dt>
                                <dd class="font-mono text-xs text-amber-900 dark:text-amber-100 truncate" title="{{ $delivery_target_pending->directory($key) }}">{{ $delivery_target_pending->directory($key) ?: 'not configured' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    <div class="mt-2 flex items-center justify-between gap-2">
                        <flux:text class="!text-xs text-amber-700 dark:text-amber-300">
                            Accept only if the website really moved. Files already uploaded to the old destination are not moved.
                        </flux:text>
                        <flux:button size="xs" variant="primary" wire:click="acceptDeliveryTarget"
                            wire:confirm="Send this show's uploads to the new destination from now on?">
                            Accept new destination
                        </flux:button>
                    </div>
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
