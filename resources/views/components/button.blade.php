<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 bg-gray-800 dark:bg-white border border-transparent rounded-md font-semibold text-xs text-white dark:text-zinc-800 uppercase tracking-widest hover:bg-gray-700 dark:hover:bg-zinc-100 focus:bg-gray-700 dark:focus:bg-zinc-100 active:bg-gray-900 dark:active:bg-zinc-200 focus:outline-hidden focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-zinc-800 disabled:opacity-50 transition ease-in-out duration-150 hover:cursor-pointer']) }}>
    {{ $slot }}
</button>
