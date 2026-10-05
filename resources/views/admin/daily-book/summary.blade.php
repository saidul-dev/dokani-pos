<x-app-layout>
    {{-- Phones: the title sits in the top bar like an app screen, so the page header
         is just the range dates; Settings is reachable from More (sidebar). --}}
    <x-slot name="mobileTitle">{{ __('Summary') }}</x-slot>

    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="hidden sm:block text-xl sm:text-2xl font-bold text-brand-900">{{ __('Daily Book — Summary') }}</h2>
                <p class="text-sm text-slate-500 sm:mt-0.5">
                    @if ($range === 'custom')
                        {{ $rangeLabel }}
                    @else
                        {{ $rangeLabel }} ({{ \Illuminate\Support\Carbon::parse($from)->translatedFormat('d M Y') }}{{ $from !== $to ? ' – '.\Illuminate\Support\Carbon::parse($to)->translatedFormat('d M Y') : '' }})
                    @endif
                </p>
            </div>
            @can('daily-book.edit')
            <a href="{{ route('daily-book.settings.edit') }}"
               class="hidden sm:inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-semibold text-slate-500 ring-1 ring-slate-200 hover:text-brand-800 hover:bg-slate-50">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 0 1 0 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 0 1 0-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
                {{ __('Settings') }}
            </a>
            @endcan
        </div>
    </x-slot>

    <div class="space-y-5 sm:space-y-6">
        <!-- Range switcher — 2x2 grid on phone (equal-width, thumb-sized), inline pills from sm up -->
        <div class="grid grid-cols-4 sm:inline-flex gap-1 rounded-xl bg-white p-1 shadow-sm ring-1 ring-slate-200 sm:w-auto w-full">
            @foreach (['day' => __('Day'), 'week' => __('Week'), 'month' => __('Month'), 'custom' => __('Custom')] as $value => $text)
            <a href="{{ route('daily-book.summary', ['range' => $value]) }}"
                class="rounded-lg px-2 sm:px-4 py-2.5 sm:py-1.5 text-center text-sm font-semibold transition-colors {{ $range === $value ? 'bg-brand-800 text-white' : 'text-slate-500 hover:text-brand-800' }}">
                {{ $text }}
            </a>
            @endforeach
        </div>

        @if ($range === 'custom')
        <form method="GET" action="{{ route('daily-book.summary') }}"
              class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 space-y-3 sm:flex sm:items-end sm:gap-3 sm:space-y-0">
            <input type="hidden" name="range" value="custom">
            <div class="grid grid-cols-2 gap-3 sm:flex sm:gap-3">
                <div class="flex-1">
                    <label for="from" class="block text-xs font-medium text-slate-500">{{ __('From') }}</label>
                    <x-text-input id="from" name="from" type="date" class="mt-1 block w-full !py-3 !text-base rounded-xl" :value="$from" />
                </div>
                <div class="flex-1">
                    <label for="to" class="block text-xs font-medium text-slate-500">{{ __('To') }}</label>
                    <x-text-input id="to" name="to" type="date" class="mt-1 block w-full !py-3 !text-base rounded-xl" :value="$to" />
                </div>
            </div>
            <button type="submit" class="w-full sm:w-auto rounded-xl bg-brand-800 px-5 py-3 sm:py-2 text-sm font-semibold text-white hover:bg-brand-700">
                {{ __('Apply') }}
            </button>
        </form>
        @endif

        <!-- Cash in Hand — deliberately NOT part of the day/week/month/custom range above:
             it's a running, all-time balance (Capital + Sale − cash actually paid out, since
             the very first entry ever logged), not a per-period figure. See
             DailyBookController::cashInHand. -->
        <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-brand-800 to-brand-900 p-4 sm:p-5 shadow-sm">
            <div class="relative flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-brand-200">{{ __('Cash in Hand') }}</p>
                    <p class="mt-1.5 text-2xl sm:text-3xl font-bold text-white break-words">{{ \App\Support\Money::format($cashInHand) }}</p>
                    <p class="mt-1 text-xs text-brand-300 sm:hidden">{{ __('All-time') }}</p>
                    <p class="mt-1 text-xs text-brand-300 hidden sm:block">{{ __('All-time — Capital + cash received (sales, customer collections) − cash paid (purchases, expenses, supplier payments)') }}</p>
                </div>
                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-white/10 text-accent-400">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3M4.5 19.5h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z"/></svg>
                </span>
            </div>
        </div>

        @php
            $stats = [
                ['label' => __('Total Purchase'), 'value' => $totalPurchase, 'icon' => 'purchase'],
                ['label' => __('Total Sale'), 'value' => $totalSale, 'icon' => 'sale'],
                ['label' => __('Total Expense'), 'value' => $totalExpense, 'icon' => 'expense'],
                ['label' => __('Gross Profit'), 'value' => $grossProfit, 'icon' => 'gross'],
            ];
        @endphp

        <!-- Stat cards — full-width stack on phone, grid from sm up -->
        <div class="grid grid-cols-2 xl:grid-cols-4 gap-3 sm:gap-4">
            @foreach ($stats as $stat)
            <div class="relative overflow-hidden rounded-2xl bg-white p-3.5 sm:p-5 shadow-sm ring-1 ring-slate-200">
                <div class="hidden sm:block absolute right-0 top-0 h-20 w-20 translate-x-6 -translate-y-6 rounded-full bg-gradient-to-br from-brand-100 to-accent-300/40"></div>
                <div class="relative flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-slate-500">{{ $stat['label'] }}</p>
                        @if ($stat['value'] === null)
                        {{-- Gross Profit with no margin % configured yet — see Settings --}}
                        <p class="mt-1 sm:mt-1.5 text-lg sm:text-2xl font-bold text-slate-300">—</p>
                        @else
                        <p class="mt-1 sm:mt-1.5 text-lg sm:text-2xl font-bold text-brand-900 break-words">{{ \App\Support\Money::format($stat['value']) }}</p>
                        @endif
                        @if ($stat['icon'] === 'gross')
                        <p class="mt-0.5 text-xs text-slate-400">
                            @if ($marginPercent !== null)
                                {{ __('Sale × :margin%', ['margin' => rtrim(rtrim(number_format($marginPercent, 2), '0'), '.')]) }}
                            @else
                                {{ __('Profit % not set') }}
                            @endif
                        </p>
                        @endif
                    </div>
                    <span class="hidden sm:grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-brand-700 to-brand-900 text-accent-400">
                        @if ($stat['icon'] === 'purchase')
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 1.887-4.598 2.24-6.62.03-.176-.114-.33-.292-.33H5.706M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z"/></svg>
                        @elseif ($stat['icon'] === 'sale')
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18 9 11.25l4.306 4.306a11.95 11.95 0 0 1 5.814-5.518l2.74-1.22m0 0-5.94-2.281m5.94 2.28-2.28 5.941"/></svg>
                        @elseif ($stat['icon'] === 'expense')
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                        @else
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3M4.5 19.5h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z"/></svg>
                        @endif
                    </span>
                </div>
            </div>
            @endforeach
        </div>

        <!-- Net Profit = Gross Profit − Expense. Gross Profit is Sale × profit % (see
             DailyBookController::summary for why Purchase isn't used as cost). -->
        <div class="rounded-2xl bg-white p-4 sm:p-5 shadow-sm ring-1 ring-slate-200">
            <div class="flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <h3 class="font-bold text-brand-900 text-sm sm:text-base">{{ __('Net Profit') }}</h3>
                    <p class="text-xs text-slate-400">{{ __('Gross Profit − Total Expense') }}</p>
                    {{-- Write-offs (মাফ) in this range — only shown when there are any. --}}
                    @if ($waivedToCustomers > 0)
                    <p class="text-xs text-slate-400">− {{ __('Waived to customers: :amount', ['amount' => \App\Support\Money::format($waivedToCustomers)]) }}</p>
                    @endif
                    @if ($waivedBySuppliers > 0)
                    <p class="text-xs text-slate-400">+ {{ __('Waived by suppliers: :amount', ['amount' => \App\Support\Money::format($waivedBySuppliers)]) }}</p>
                    @endif
                </div>
                @if ($netProfit === null)
                <p class="shrink-0 text-xl sm:text-2xl font-bold text-slate-300">—</p>
                @else
                <p class="shrink-0 text-xl sm:text-2xl font-bold {{ $netProfit >= 0 ? 'text-emerald-600' : 'text-rose-500' }}">
                    {{ \App\Support\Money::format($netProfit) }}
                </p>
                @endif
            </div>
        </div>

        @if ($marginPercent === null)
        <div class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200">
            {{ __('Set your profit % to see Gross and Net Profit — profit is calculated as Total Sale × profit %.') }}
            @can('daily-book.edit')
            <a href="{{ route('daily-book.settings.edit') }}" class="font-semibold underline">{{ __('Set profit %') }}</a>
            @endcan
        </div>
        @endif

        {{-- Site is hidden on the Daily Book phone screens, so this note only confuses there. --}}
        <p class="hidden sm:block text-xs text-slate-400 px-1">
            {{ __('Sale, Purchase, and Capital totals are for your currently selected site. Expense totals are company-wide.') }}
        </p>
    </div>
</x-app-layout>
