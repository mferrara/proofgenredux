@props(['active'])

@php
$classes = ($active ?? false)
            ? 'block w-full ps-3 pe-4 py-2 border-l-4 border-indigo-400 dark:border-indigo-500 text-start text-base font-medium text-indigo-700 dark:text-indigo-300 bg-indigo-50 dark:bg-indigo-500/10 focus:outline-hidden focus:text-indigo-800 dark:focus:text-indigo-200 focus:bg-indigo-100 dark:focus:bg-indigo-500/20 focus:border-indigo-700 dark:focus:border-indigo-400 transition duration-150 ease-in-out'
            : 'block w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-gray-600 dark:text-zinc-300 hover:text-gray-800 dark:hover:text-white hover:bg-gray-50 dark:hover:bg-zinc-700/50 hover:border-gray-300 dark:hover:border-zinc-600 focus:outline-hidden focus:text-gray-800 dark:focus:text-white focus:bg-gray-50 dark:focus:bg-zinc-700/50 focus:border-gray-300 dark:focus:border-zinc-600 transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
