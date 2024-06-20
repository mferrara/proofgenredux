<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? 'Page Title' }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <!-- Styles -->
        @livewireStyles
    </head>
    <body class="font-sans antialiased">
        <x-banner />

        <div class="min-h-screen bg-gray-100">
            @livewire('navigation-menu')

            <!-- Page Heading -->
            @if (isset($header))
                <header class="bg-white shadow">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endif

            <div class="-mt-2 w-full bg-indigo-50 border border-b-indigo-100 px-2 py-1 text-indigo-700 flex flex-row justify-end items-center gap-x-2">
                <div class="text-sm font-semibold">
                    Backup:
                    @if(config('proofgen.archive_enabled'))
                        <span class="text-green-700 px-1 py-0.5 font-semibold">Enabled</span>
                        <?php
                        // Ensure the archive path is reachable
                        $archive_reachable = true;
                        try {
                            $listing = \Illuminate\Support\Facades\Storage::disk('archive')->directories();
                        }catch(\Exception $e){
                            $archive_reachable = false;
                        }
                        ?>
                        @if( ! $archive_reachable)
                            <span class="text-red-700 px-1 py-0.5 font-semibold">Archive path unreachable</span>
                        @endif
                    @else
                        <span class="text-yellow-700 px-1 py-0.5 font-semibold">Disabled</span>
                    @endif
                </div>
                <div class="text-sm font-semibold">
                    Uploads:
                    @if(config('proofgen.upload_proofs'))
                        <span class="text-green-700 px-1 py-0.5 font-semibold">Enabled</span>
                    @else
                        <span class="text-yellow-700 px-1 py-0.5 font-semibold">Disabled</span>
                    @endif
                </div>
                <div class="text-sm font-semibold">
                    Rename:
                    @if(config('proofgen.rename_files'))
                        <span class="text-green-700 px-1 py-0.5 font-semibold">Enabled</span>
                    @else
                        <span class="text-yellow-700 px-1 py-0.5 font-semibold">Disabled</span>
                    @endif
                </div>
            </div>

            <!-- Page Content -->
            <main>
                {{ $slot }}
            </main>
        </div>

        @stack('modals')

        @livewireScripts
    </body>
</html>
