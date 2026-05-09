@props(['active'])

@php
$classes = ($active ?? false)
            ? 'inline-flex items-center px-1 pt-1 border-b-2 border-indigo-400 dark:border-indigo-500 text-sm font-medium leading-5 text-gray-900 dark:text-white focus:outline-hidden focus:border-indigo-700 dark:focus:border-indigo-400 transition duration-150 ease-in-out'
            : 'inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-gray-500 dark:text-zinc-400 hover:text-gray-700 dark:hover:text-zinc-200 hover:border-gray-300 dark:hover:border-zinc-600 focus:outline-hidden focus:text-gray-700 dark:focus:text-zinc-200 focus:border-gray-300 dark:focus:border-zinc-600 transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
