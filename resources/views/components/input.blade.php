@props(['disabled' => false])

<input {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge(['class' => 'border-gray-300 dark:border-zinc-600 bg-white dark:bg-zinc-900 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-zinc-500 focus:border-indigo-500 dark:focus:border-indigo-400 focus:ring-indigo-400 dark:focus:ring-indigo-500 rounded-md shadow-xs']) !!}>
