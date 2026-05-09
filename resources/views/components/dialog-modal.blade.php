@props(['id' => null, 'maxWidth' => null])

<x-modal :id="$id" :maxWidth="$maxWidth" {{ $attributes }}>
    <div class="px-6 py-4">
        <div class="text-lg font-medium text-gray-900 dark:text-white">
            {{ $title }}
        </div>

        <div class="mt-4 text-sm text-gray-600 dark:text-zinc-300">
            {{ $content }}
        </div>
    </div>

    <div class="flex flex-row justify-end px-6 py-4 bg-gray-100 dark:bg-zinc-900/60 border-t border-gray-200 dark:border-zinc-700 text-end">
        {{ $footer }}
    </div>
</x-modal>
