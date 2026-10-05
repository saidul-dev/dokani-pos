<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('Sign In') }} — {{ config('app.name', 'Business ERP') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link
        href="https://fonts.bunny.net/css?family=figtree:400,500,600,700|fraunces:300,400,500,600,600italic,300italic|ibm-plex-mono:500,600&display=swap"
        rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        .font-display {
            font-family: 'Fraunces', ui-serif, Georgia, serif;
        }

        .font-mono-label {
            font-family: 'IBM Plex Mono', ui-monospace, monospace;
        }

        .headline-oversized {
            font-family: 'Fraunces', ui-serif, Georgia, serif;
            font-weight: 600;
            font-size: clamp(2.75rem, 8vw, 6.75rem);
            line-height: 0.85;
            letter-spacing: -0.02em;
            color: #eae3d1;
        }

        .fade-row {
            opacity: 0;
            transform: translateY(6px);
            animation: fade-in .5s ease-out forwards;
        }

        .fade-row:nth-child(1) {
            animation-delay: .1s;
        }

        .fade-row:nth-child(2) {
            animation-delay: .18s;
        }

        .fade-row:nth-child(3) {
            animation-delay: .26s;
        }

        .fade-row:nth-child(4) {
            animation-delay: .34s;
        }

        .fade-row:nth-child(5) {
            animation-delay: .42s;
        }

        .fade-row:nth-child(6) {
            animation-delay: .5s;
        }

        @keyframes fade-in {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .fade-row {
                opacity: 1;
                transform: none;
                animation: none;
            }
        }
    </style>
</head>

<body class="font-sans antialiased text-slate-900 bg-[#fbf9f4]">
    <div class="mx-auto max-w-6xl px-6 py-10 sm:px-10 sm:py-14 lg:py-20">

        {{-- Top: logo + wordmark --}}
        <a href="{{ route('home') }}" class="inline-flex items-center gap-3">
            <x-application-logo class="h-8 w-8 shrink-0" />
            <span class="font-mono-label text-[10px] tracking-[0.15em] text-slate-500 uppercase">{{
                __('Unified ERP for Retail, Wholesale & Online Business') }}</span>
        </a>

        {{-- Hero: oversized headline (decorative background) + tagline & card (normal flow, layered on top) --}}
        {{-- Mobile: card appears first (usable immediately). Large screens: original side-by-side layout via lg:order-*. --}}
        <div class="relative mt-8 flex flex-col gap-6 lg:mt-10 lg:flex-row lg:items-start lg:justify-between lg:gap-10">
            <h1 class="headline-oversized pointer-events-none select-none order-2 lg:order-none lg:absolute lg:inset-x-0 lg:top-0 lg:z-0">{{
                __('Sign in.') }}</h1>

            {{-- Sign-in card --}}
            <div
                class="relative z-10 order-1 w-full max-w-sm shrink-0 rounded-2xl bg-gradient-to-br from-brand-900 to-brand-950 p-6 shadow-2xl shadow-brand-950/30 sm:p-7 lg:order-2 lg:mt-4">
                <p class="font-mono-label text-[10px] tracking-[0.18em] text-accent-300/80 uppercase">{{ __('Welcome back') }}</p>
                <h2 class="font-display mt-1 text-2xl font-medium text-white">{{ __('Sign in') }}</h2>

                @if (session('status'))
                <div class="mt-4 rounded-lg bg-emerald-400/10 px-3.5 py-2 text-sm font-medium text-emerald-300 ring-1 ring-emerald-400/30">
                    {{ session('status') }}
                </div>
                @endif

                <form method="POST" action="{{ route('login') }}" class="mt-5 space-y-4">
                    @csrf

                    <div>
                        <label for="email" class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-brand-200/70">{{ __('Email') }}</label>
                        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus
                            autocomplete="username"
                            class="w-full rounded-lg border-white/15 bg-white/5 text-sm text-white placeholder-white/30 focus:border-accent-400 focus:ring-accent-400">
                        @error('email') <p class="mt-1.5 text-xs font-medium text-rose-300">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="password" class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-brand-200/70">{{ __('Password') }}</label>
                        <input id="password" type="password" name="password" required autocomplete="current-password"
                            class="w-full rounded-lg border-white/15 bg-white/5 text-sm text-white placeholder-white/30 focus:border-accent-400 focus:ring-accent-400">
                        @error('password') <p class="mt-1.5 text-xs font-medium text-rose-300">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex items-center justify-between pt-1">
                        <label for="remember_me" class="inline-flex items-center gap-2 text-sm text-brand-200/80">
                            <input id="remember_me" type="checkbox" name="remember"
                                class="rounded border-white/20 bg-white/5 text-accent-400 focus:ring-accent-400">
                            {{ __('Remember me') }}
                        </label>

                        @if (Route::has('password.request'))
                        <a href="{{ route('password.request') }}" class="text-sm font-medium text-accent-300 hover:text-accent-200">{{ __('Forgot password?') }}</a>
                        @endif
                    </div>

                    <button type="submit"
                        class="w-full rounded-lg bg-gradient-to-r from-[#d4a94f] to-[#e8c377] px-4 py-3 text-sm font-semibold text-[#3c2e0a] shadow-sm transition hover:from-[#e0b45a] hover:to-[#f0ce8f] focus:outline-none focus:ring-2 focus:ring-accent-400 focus:ring-offset-2 focus:ring-offset-brand-900">
                        {{ __('Sign in') }}
                    </button>
                </form>

                @php
                $demoAccounts = [
                ['role' => 'Super Admin', 'email' => 'admin@businesserp.test'],
                ['role' => 'Company Admin', 'email' => 'owner@businesserp.test'],
                ['role' => 'Shop Owner (Daily Book)', 'email' => 'dailybook@businesserp.test'],
                ['role' => 'Branch Manager', 'email' => 'manager@businesserp.test'],
                ['role' => 'HR Executive', 'email' => 'hr@businesserp.test'],
                ['role' => 'Head Accountant', 'email' => 'accountant@businesserp.test'],
                ['role' => 'Store Keeper', 'email' => 'storekeeper@businesserp.test'],
                ['role' => 'Sales Person', 'email' => 'sales@businesserp.test'],
                ];
                @endphp
                <div class="mt-5 border-t border-white/10 pt-4">
                    <p class="font-mono-label text-[9px] tracking-[0.16em] text-brand-300/60 uppercase">{{ __('Quick fill') }} &middot; {{ __('demo accounts') }}</p>
                    <div class="mt-2.5 flex flex-wrap gap-1.5">
                        @foreach ($demoAccounts as $account)
                        <button type="button"
                            onclick="document.getElementById('email').value = '{{ $account['email'] }}'; document.getElementById('password').value = 'password';"
                            class="rounded-full bg-white/5 px-2.5 py-1 text-[11px] font-medium text-brand-100/90 ring-1 ring-white/10 hover:bg-white/10 hover:text-white">
                            {{ $account['role'] }}
                        </button>
                        @endforeach
                    </div>
                    <p class="mt-2 text-[10px] text-brand-300/50">{{ __('Password for all demo accounts:') }} <span class="font-mono-label text-brand-200/70">password</span></p>
                </div>
            </div>

            <div class="relative z-10 order-3 max-w-sm lg:order-1 lg:mt-24">
                <p class="font-display text-base italic text-[#3c3527]">
                    {{ __('Every sale.') }} {{ __('Every transaction.') }}
                    <span class="not-italic font-semibold text-brand-900">{{ __('One system.') }}</span>
                </p>
                <p class="mt-2.5 text-sm leading-relaxed text-[#8a8577]">
                    {{ __('The operational backbone for modern businesses — from purchasing and inventory to
                    accounting,
                    POS, and eCommerce, all in one platform.') }}
                </p>

                {{-- Module list --}}
                <div class="mt-8 border-t border-[#e9e5d8] pt-5">
                    <p class="font-mono-label text-[10px] tracking-[0.18em] text-[#a39d8a] uppercase">{{
                        __('What\'s inside') }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ([__('Purchase, Sales & POS'), __('Inventory & Multi-Warehouse'), __('E-commerce
                        & Delivery'), __('Accounts & Reports'), __('Customer & Supplier Management'), __('HRM')]
                        as $module)
                        <span
                            class="fade-row rounded-full border border-[#e2ddcc] px-3 py-1.5 text-xs font-medium text-[#4a4636]">
                            {{ $module }}
                        </span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        {{-- Footer --}}
        <p class="mt-10 text-center text-xs text-[#b0ab99] lg:text-left">
            &copy; {{ date('Y') }} {{ config('app.name', 'Business ERP') }} &middot; {{ __('Enterprise Suite') }}
            &middot; {{ __('Powered by') }} <a href="https://vexasoft.net" target="_blank" rel="noopener"
                class="font-semibold text-[#8a8577] hover:text-brand-700">Vexasoft</a>
        </p>
    </div>
</body>

</html>
