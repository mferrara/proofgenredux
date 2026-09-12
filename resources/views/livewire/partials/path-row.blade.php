{{--
    A row showing a labeled filesystem path with copy-to-clipboard and open-in-finder.

    @props:
        label - badge label, e.g. "New photos"
        color - badge color (zinc|blue|amber|emerald|...)
        path  - the filesystem path
        description - extra context shown after the badge (optional)
--}}
@props([
    'label',
    'color' => 'zinc',
    'path',
    'description' => null,
])

<div class="flex flex-wrap items-center gap-2 py-2"
     x-data="{
        copied: false,
        copyPath() {
            if (navigator.clipboard) {
                navigator.clipboard.writeText(@js($path));
            } else {
                const el = document.createElement('textarea');
                el.value = @js($path);
                el.style.position = 'absolute';
                el.style.left = '-9999px';
                document.body.appendChild(el);
                el.select();
                document.execCommand('copy');
                document.body.removeChild(el);
            }
            this.copied = true;
            setTimeout(() => this.copied = false, 1800);
        }
    }">
    <flux:badge :color="$color" size="sm">{{ $label }}</flux:badge>
    @if($description)
        <span class="text-sm text-zinc-600 dark:text-zinc-400">{{ $description }}</span>
    @endif
    <code class="font-mono text-xs px-2 py-1 rounded
                 bg-zinc-100 dark:bg-white/[0.06]
                 border border-zinc-200 dark:border-white/10
                 text-zinc-700 dark:text-zinc-300 break-all">{{ $path }}</code>
    <flux:button
        type="button"
        x-on:click="copyPath()"
        size="xs"
        variant="ghost"
        square
        :tooltip="'Copy path'"
    >
        <template x-if="!copied"><flux:icon.document-duplicate class="size-3.5" /></template>
        <template x-if="copied"><flux:icon.check class="size-3.5 !text-emerald-500" /></template>
    </flux:button>
    <flux:button
        type="button"
        wire:click="openFolder({{ \Illuminate\Support\Js::from($path) }})"
        size="xs"
        variant="ghost"
        square
        icon="folder-open"
        tooltip="Open in Finder"
    />
</div>
