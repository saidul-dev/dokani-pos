<x-app-layout>
    <x-slot name="title">{{ __('Daily Book Settings') }}</x-slot>
    <x-slot name="header">
        <div>
            <h2 class="text-xl sm:text-2xl font-bold text-brand-900">{{ __('Daily Book Settings') }}</h2>
            <p class="text-sm text-slate-500 mt-0.5">
                {{ __('An approximate profit margin, used only to estimate profit on the Daily Book Summary.') }}
            </p>
        </div>
    </x-slot>

    @if (session('success'))
    <div class="mb-6 rounded-xl bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 ring-1 ring-emerald-200">
        {{ session('success') }}
    </div>
    @endif

    <form method="POST" action="{{ route('daily-book.settings.update') }}" class="w-full">
        @csrf
        @method('PUT')

        <div class="rounded-2xl bg-white p-4 sm:p-6 shadow-sm ring-1 ring-slate-200 space-y-4">
            <div>
                <x-input-label for="daily_book_profit_margin_percent" :value="__('Approximate Profit Margin (%)')" class="text-sm font-semibold" />
                <div class="relative mt-1.5 max-w-xs">
                    <x-text-input id="daily_book_profit_margin_percent" name="daily_book_profit_margin_percent"
                                  type="number" inputmode="decimal" step="0.01" min="0" max="100"
                                  class="block w-full !py-3.5 !pr-9 !text-base rounded-xl"
                                  :value="old('daily_book_profit_margin_percent', $company->daily_book_profit_margin_percent)"
                                  placeholder="{{ __('e.g. 15') }}" />
                    <span class="pointer-events-none absolute inset-y-0 right-4 flex items-center text-base font-semibold text-slate-400">%</span>
                </div>
                <x-input-error class="mt-2" :messages="$errors->get('daily_book_profit_margin_percent')" />
            </div>

            <div class="rounded-xl bg-amber-50 px-4 py-3 ring-1 ring-amber-200">
                <p class="text-xs text-amber-800">
                    {{ __('This is a guess, not a real calculation — Daily Book has no product cost, so it can\'t know your actual profit. Set this to roughly what you usually make on what you sell (e.g. if you generally keep about ৳15 for every ৳100 you sell, enter 15). Estimated Profit on the Summary page is simply Total Sale × this percentage.') }}
                </p>
            </div>

            <p class="text-xs text-slate-400">
                {{ __('Leave this blank to hide the Estimated Profit figure on the Summary page.') }}
            </p>
        </div>

        <!-- Desktop action -->
        <div class="hidden sm:block mt-5">
            <x-primary-button>{{ __('Save Changes') }}</x-primary-button>
        </div>

        <!-- Mobile: sticky bottom action bar, app-style -->
        <div class="sm:hidden fixed inset-x-0 bottom-0 z-40 border-t border-slate-200 bg-white/95 backdrop-blur px-4 pt-3"
             style="padding-bottom: max(0.75rem, env(safe-area-inset-bottom));">
            <button type="submit" class="w-full rounded-xl bg-brand-800 px-4 py-3.5 text-center text-sm font-bold text-white shadow-sm active:bg-brand-900">
                {{ __('Save Changes') }}
            </button>
        </div>
        <div class="h-20 sm:hidden"></div>
    </form>
</x-app-layout>
