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
        @fluxAppearance
    </head>
    <body class="font-sans antialiased">
        <x-banner />

        <div class="min-h-screen">
            @livewire('navigation-menu')

            <!-- Page Heading -->
            @if (isset($header))
                <header class="shadow-sm">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endif

            <livewire:app-status-bar />

            <!-- Page Content -->
            <main>
                {{ $slot }}
            </main>
            <footer class="mt-16">
                <div class="max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
                    <div class="text-center text-gray-500 text-sm space-y-1">
                        <div>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</div>
                        <div class="text-xs text-gray-600">
                            Version {{ \App\Services\VersionService::getVersion() }} &bull; Current time: {{ now()->format('m/d/Y H:i:s') }}
                        </div>
                    </div>
                </div>
            </footer>
        </div>

        @stack('modals')
        @fluxScripts
        <flux:toast />
    </body>
</html>
