<div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-gray-100 dark:bg-zinc-950">
    <div>
        {{ $logo }}
    </div>

    <div class="w-full sm:max-w-md mt-6 px-6 py-4 bg-white dark:bg-zinc-800 border border-gray-200 dark:border-zinc-700 shadow-md overflow-hidden sm:rounded-lg">
        {{ $slot }}
    </div>
</div>
