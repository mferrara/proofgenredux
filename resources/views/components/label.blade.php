@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-medium text-sm text-gray-700 dark:text-zinc-200']) }}>
    {{ $value ?? $slot }}
</label>
