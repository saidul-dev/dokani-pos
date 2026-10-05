<x-app-layout>
    <x-slot name="title">{{ __('Suppliers') }}</x-slot>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-xl sm:text-2xl font-bold text-brand-900">{{ __('Daily Book — Suppliers') }}</h2>
                <p class="text-sm text-slate-500 mt-0.5">{{ __('Suppliers / wholesalers you buy from, and how much you owe each.') }}</p>
            </div>
            {{-- The header slot renders outside the page body's Alpine scope, so
                 this opens the form below via a window event. --}}
            <button type="button" x-data x-on:click="$dispatch('open-add-supplier')"
                    class="flex w-full sm:w-auto items-center justify-center gap-2 rounded-lg bg-brand-800 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                {{ __('Add Supplier') }}
            </button>
        </div>
    </x-slot>

    <div class="space-y-5">
        <!-- Add Supplier — name + phone, same as the purchase form's quick add.
             Reopens with its errors if validation failed. -->
        <form x-data="{ open: @js(filled(old('add_supplier'))) }"
              x-on:open-add-supplier.window="open = true; $nextTick(() => $refs.name.focus())"
              x-show="open" x-cloak method="POST" action="{{ route('daily-book.suppliers.store') }}"
              class="rounded-2xl bg-white p-4 sm:p-5 shadow-sm ring-1 ring-slate-200">
            @csrf
            <input type="hidden" name="add_supplier" value="1">
            <div class="flex items-center justify-between gap-3">
                <h3 class="font-bold text-brand-900">{{ __('Add Supplier') }}</h3>
                <button type="button" x-on:click="open = false" class="text-sm font-semibold text-slate-400 hover:text-slate-600">{{ __('Cancel') }}</button>
            </div>
            <div class="mt-3 grid grid-cols-1 sm:grid-cols-[1fr_1fr_auto] gap-3">
                <div>
                    <label for="supplier_name" class="block text-xs font-medium text-slate-500">{{ __('Supplier name') }}</label>
                    <x-text-input id="supplier_name" name="name" type="text" x-ref="name"
                                  class="mt-1 block w-full !py-3 !text-base rounded-xl"
                                  :value="old('add_supplier') ? old('name') : ''" required />
                    <x-input-error class="mt-1.5" :messages="old('add_supplier') ? $errors->get('name') : []" />
                </div>
                <div>
                    <label for="supplier_phone" class="block text-xs font-medium text-slate-500">{{ __('Phone number') }}</label>
                    <x-text-input id="supplier_phone" name="phone" type="tel" inputmode="tel"
                                  class="mt-1 block w-full !py-3 !text-base rounded-xl"
                                  :value="old('add_supplier') ? old('phone') : ''" required />
                    <x-input-error class="mt-1.5" :messages="old('add_supplier') ? $errors->get('phone') : []" />
                </div>
                <div class="flex items-start sm:pt-5">
                    <button type="submit" class="w-full rounded-xl bg-brand-800 px-5 py-3 text-sm font-bold text-white hover:bg-brand-700">
                        {{ __('Save') }}
                    </button>
                </div>
            </div>
        </form>

        <!-- Total due across every supplier (all-time, company-wide) -->
        <div class="rounded-2xl bg-gradient-to-br from-brand-800 to-brand-900 p-4 sm:p-5 shadow-sm">
            <p class="text-sm font-medium text-brand-200">{{ __('Total you owe') }}</p>
            <p class="mt-1.5 text-2xl sm:text-3xl font-bold text-white">{{ number_format($totalDue, 2) }}</p>
            <p class="mt-1 text-xs text-brand-300">
                {{ __(':count suppliers with a due', ['count' => $suppliers->where('daily_book_due', '>', 0)->count()]) }}
            </p>
        </div>

        {{-- Quick Pay errors only — Add Supplier shows its own inline. --}}
        @if ($errors->any() && ! old('add_supplier'))
        <div class="rounded-xl bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700 ring-1 ring-rose-200">
            {{ $errors->first() }}
        </div>
        @endif

        {{-- ledgerUrl is set by the row clicked; the modal below fetches it on open. --}}
        <div x-data="{ ledgerUrl: null }">
        <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 divide-y divide-slate-100">
            @forelse ($suppliers as $supplier)
            {{-- Reopen the pay form that failed validation, so the owner sees what to fix. --}}
            <div x-data="{ paying: @js((int) old('pay_party_id') === $supplier->id) }" class="p-4 sm:px-5">
                {{-- Clicking the row opens this supplier's ledger; Quick Pay and the
                     phone link stop the click so they keep doing their own job. --}}
                <div role="button" tabindex="0"
                     x-on:click="ledgerUrl = @js(route('daily-book.suppliers.ledger', $supplier)); $dispatch('open-modal', 'supplier-ledger')"
                     x-on:keydown.enter="$el.click()"
                     class="-m-2 flex cursor-pointer items-center justify-between gap-3 rounded-xl p-2 hover:bg-slate-50">
                    <div class="min-w-0">
                        <p class="truncate font-semibold text-slate-800">{{ $supplier->name }}</p>
                        <p class="text-sm text-slate-500">
                            <a href="tel:{{ $supplier->phone }}" x-on:click.stop class="hover:text-brand-800">{{ $supplier->phone }}</a>
                            <span class="text-slate-300">·</span>
                            <span class="text-xs font-semibold text-brand-700">{{ __('View ledger') }} ›</span>
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-3">
                        <div class="text-right">
                            <p class="text-[11px] uppercase tracking-wide text-slate-400">{{ __('Due') }}</p>
                            <p class="text-lg font-bold {{ $supplier->daily_book_due > 0 ? 'text-rose-600' : 'text-slate-300' }}">
                                {{ number_format($supplier->daily_book_due, 2) }}
                            </p>
                        </div>
                        @if ($supplier->daily_book_due > 0)
                        <button type="button" x-on:click.stop="paying = ! paying"
                                class="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700"
                                x-bind:class="paying && 'bg-slate-500 hover:bg-slate-600'">
                            <span x-show="! paying">{{ __('Quick Pay') }}</span>
                            <span x-show="paying" x-cloak>{{ __('Close') }}</span>
                        </button>
                        @endif
                    </div>
                </div>

                @if ($supplier->daily_book_due > 0)
                <form x-show="paying" x-cloak method="POST" action="{{ route('daily-book.suppliers.pay', $supplier) }}"
                      class="mt-3 grid grid-cols-1 sm:grid-cols-[1fr_1fr_2fr_auto] gap-3 rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200">
                    @csrf
                    <input type="hidden" name="pay_party_id" value="{{ $supplier->id }}">
                    <div>
                        <label class="block text-xs font-medium text-slate-500">{{ __('Amount') }}</label>
                        <x-text-input name="amount" type="number" inputmode="decimal" step="0.01" min="0.01" max="{{ $supplier->daily_book_due }}"
                                      class="mt-1 block w-full !py-3 !text-base rounded-xl"
                                      :value="(int) old('pay_party_id') === $supplier->id ? old('amount') : $supplier->daily_book_due" required />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500">{{ __('Date') }}</label>
                        <x-text-input name="entry_date" type="date"
                                      class="mt-1 block w-full !py-3 !text-base rounded-xl"
                                      :value="(int) old('pay_party_id') === $supplier->id ? old('entry_date') : today()->toDateString()" required />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500">{{ __('Note (optional)') }}</label>
                        <x-text-input name="note" type="text"
                                      class="mt-1 block w-full !py-3 !text-base rounded-xl"
                                      :value="(int) old('pay_party_id') === $supplier->id ? old('note') : ''" placeholder="{{ __('e.g. Paid in cash, bKash…') }}" />
                    </div>
                    <div class="flex items-end">
                        <button type="submit" class="w-full rounded-xl bg-emerald-600 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-700">
                            {{ __('Pay') }}
                        </button>
                    </div>
                </form>
                @endif
            </div>
            @empty
            <div class="px-5 py-10 text-center text-sm text-slate-400">
                {{ __('No suppliers yet. Add one with "Add Supplier" above, or from a Purchase Entry.') }}
            </div>
            @endforelse
        </div>

        {{-- Supplier ledger. x-modal remounts its slot on every open, so this
             fetch runs fresh each time for whichever row set ledgerUrl. --}}
        <x-modal name="supplier-ledger" maxWidth="2xl">
            <div class="relative" x-data="{ html: '', loading: true, failed: false }"
                 x-init="fetch(ledgerUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                            .then(r => r.ok ? r.text() : Promise.reject(r))
                            .then(t => { html = t; loading = false })
                            .catch(() => { failed = true; loading = false })">
                <button type="button" x-on:click="$dispatch('close-modal', 'supplier-ledger')"
                        class="absolute right-3 top-3 z-10 grid h-9 w-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                        aria-label="{{ __('Close') }}">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                </button>
                <p x-show="loading" class="px-6 py-12 text-center text-sm text-slate-400">{{ __('Loading…') }}</p>
                <p x-show="failed" x-cloak class="px-6 py-12 text-center text-sm text-rose-600">{{ __('Couldn\'t load the ledger. Please try again.') }}</p>
                <div x-html="html"></div>
            </div>
        </x-modal>
        </div>

        <p class="text-xs text-slate-400 px-1">
            {{ __('Dues here come from Daily Book purchases you didn\'t fully pay, minus payments made from this page.') }}
        </p>
    </div>
</x-app-layout>
