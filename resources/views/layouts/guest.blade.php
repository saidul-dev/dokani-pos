<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Business ERP') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-slate-900 antialiased">
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-gradient-to-br from-brand-950 via-brand-900 to-brand-800 relative overflow-hidden">
            <!-- Decorative cyan glow -->
            <div class="pointer-events-none absolute -top-32 -right-32 h-96 w-96 rounded-full bg-accent-500/20 blur-3xl"></div>
            <div class="pointer-events-none absolute -bottom-32 -left-32 h-96 w-96 rounded-full bg-brand-500/30 blur-3xl"></div>

            <div class="relative z-10 flex flex-col items-center">
                <a href="{{ route('home') }}" class="flex items-center gap-3">
                    <x-application-logo class="w-14 h-14" />
                    <div class="leading-tight">
                        <span class="block text-2xl font-bold text-white tracking-wide">Business ERP</span>
                        <span class="block text-xs font-medium text-accent-400">Any product business. Only the modules you need.</span>
                    </div>
                </a>
            </div>

            <div class="relative z-10 w-full sm:max-w-md mt-8 px-6 py-6 bg-white shadow-2xl shadow-brand-950/50 overflow-hidden sm:rounded-2xl">
                {{ $slot }}
            </div>

            <p class="relative z-10 mt-6 text-xs text-brand-300/70">
                &copy; {{ date('Y') }} Business ERP &middot; Enterprise Suite
                &middot; Powered by <a href="https://vexasoft.net" target="_blank" rel="noopener" class="font-semibold hover:text-white">Vexasoft</a>
            </p>
        </div>
    </body>
</html>
