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
        @fluxAppearance
    </head>
    <body class="font-sans antialiased">
        <x-banner />

        <div class="min-h-screen">
            @livewire('navigation-menu')

            <!-- Page Heading -->
            @if (isset($header))
                <header class="shadow">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endif

            <div class="w-full bg-gray-700 border border-gray-600 px-2 py-1 flex flex-row justify-end items-center gap-x-2">
                <div class="text-sm font-semibold">
                    Backup:
                    @if(config('proofgen.archive_enabled'))
                        <span class="text-green-700 px-1 py-0.5 font-semibold">Enabled</span>
                        @php
                        // Ensure the archive path is reachable
                        $archive_reachable = true;
                        try {
                            $listing = \Illuminate\Support\Facades\Storage::disk('archive')->directories();
                        }catch(\Exception $e){
                            $archive_reachable = false;
                        }
                        @endphp
                        @if( ! $archive_reachable)
                            <span class="text-red-500 px-1 py-0.5 font-semibold">Archive path unreachable</span>
                        @endif
                    @else
                        <span class="text-rose-500 px-1 py-0.5 font-semibold">Disabled</span>
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
                <div class="text-sm font-semibold flex flex-row items-center">
                    <div>Horizon: &nbsp;</div>
                    @if(shell_exec("ps aux | grep '[a]rtisan horizon'"))
                        <span class="text-green-700 px-1 py-0.5 font-semibold">Running</span>
                    @else
                        <flux:heading class="flex items-center !font-semibold !text-rose-500">
                            Stopped
                            <flux:tooltip toggleable>
                                <flux:button icon="information-circle" size="xs" variant="ghost" class="!text-rose-500/80" />

                                <flux:tooltip.content class="max-w-[20rem] space-y-2">
                                    <p>Horizon is needed to process tasks.</p>
                                    <p>Run `php artisan horizon` in the terminal</p>
                                </flux:tooltip.content>
                            </flux:tooltip>
                        </flux:heading>
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
        @fluxScripts
    </body>
</html>
