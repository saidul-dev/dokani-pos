{{-- Suppliers and Customers share this one list (DailyBookController::partyIndex).
     $side is 'supplier' or 'customer'; everything side-specific is in $t below. --}}
@php
    $isCustomer = $side === 'customer';
    $routeBase = $isCustomer ? 'daily-book.customers' : 'daily-book.suppliers';
    $settleRoute = $isCustomer ? 'daily-book.customers.collect' : 'daily-book.suppliers.pay';

    $t = $isCustomer ? [
        'title' => __('Customers'),
        'heading' => __('Daily Book — Customers'),
        'subtitle' => __('Customers who buy on credit, and how much each owes you.'),
        'add' => __('Add Customer'),
        'name' => __('Customer name'),
        'total' => __('Total customers owe you'),
        'count' => __(':count customers with a due', ['count' => $parties->where('daily_book_due', '>', 0)->count()]),
        'settle' => __('Quick Collect'),
        'settleSubmit' => __('Collect'),
        'waive' => __('Waive the rest (let them off the remaining due)'),
        'notePlaceholder' => __('e.g. Received in cash, bKash…'),
        'empty' => __('No customers yet. Add one with "Add Customer" above, or from a Sale Entry.'),
        'footer' => __('Dues here come from Daily Book sales that weren\'t fully paid, minus collections made from this page. Walk-in sales are always paid in full, so the Walk-in Customer never has a due.'),
    ] : [
        'title' => __('Suppliers'),
        'heading' => __('Daily Book — Suppliers'),
        'subtitle' => __('Suppliers / wholesalers you buy from, and how much you owe each.'),
        'add' => __('Add Supplier'),
        'name' => __('Supplier name'),
        'total' => __('Total you owe'),
        'count' => __(':count suppliers with a due', ['count' => $parties->where('daily_book_due', '>', 0)->count()]),
        'settle' => __('Quick Pay'),
        'settleSubmit' => __('Pay'),
        'waive' => __('Supplier waived the rest (they let you off the remaining due)'),
        'notePlaceholder' => __('e.g. Paid in cash, bKash…'),
        'empty' => __('No suppliers yet. Add one with "Add Supplier" above, or from a Purchase Entry.'),
        'footer' => __('Dues here come from Daily Book purchases you didn\'t fully pay, minus payments made from this page.'),
    ];
@endphp
<x-app-layout>
    <x-slot name="title">{{ $t['title'] }}</x-slot>
    {{-- Phones: app-bar title instead of the heading + explainer. --}}
    <x-slot name="mobileTitle">{{ $t['title'] }}</x-slot>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="hidden sm:block">
                <h2 class="text-xl sm:text-2xl font-bold text-brand-900">{{ $t['heading'] }}</h2>
                <p class="text-sm text-slate-500 mt-0.5">{{ $t['subtitle'] }}</p>
            </div>
            {{-- The header slot renders outside the page body's Alpine scope, so
                 this opens the form below via a window event. --}}
            <button type="button" x-data x-on:click="$dispatch('open-add-party')"
                    class="flex w-full sm:w-auto items-center justify-center gap-2 rounded-lg bg-brand-800 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                {{ $t['add'] }}
            </button>
        </div>
    </x-slot>

    <div class="space-y-5">
        <!-- Add Supplier / Add Customer — name + phone, same as the entry form's
             quick add. Reopens with its errors if validation failed. -->
        <form x-data="{ open: @js(filled(old('add_party'))) }"
              x-on:open-add-party.window="open = true; $nextTick(() => $refs.name.focus())"
              x-show="open" x-cloak method="POST" action="{{ route($routeBase.'.store') }}"
              class="rounded-2xl bg-white p-4 sm:p-5 shadow-sm ring-1 ring-slate-200">
            @csrf
            <input type="hidden" name="add_party" value="1">
            <div class="flex items-center justify-between gap-3">
                <h3 class="font-bold text-brand-900">{{ $t['add'] }}</h3>
                <button type="button" x-on:click="open = false" class="text-sm font-semibold text-slate-400 hover:text-slate-600">{{ __('Cancel') }}</button>
            </div>
            <div class="mt-3 grid grid-cols-1 sm:grid-cols-[1fr_1fr_auto] gap-3">
                <div>
                    <label for="party_name" class="block text-xs font-medium text-slate-500">{{ $t['name'] }}</label>
                    <x-text-input id="party_name" name="name" type="text" x-ref="name"
                                  class="mt-1 block w-full !py-3 !text-base rounded-xl"
                                  :value="old('add_party') ? old('name') : ''" required />
                    <x-input-error class="mt-1.5" :messages="old('add_party') ? $errors->get('name') : []" />
                </div>
                <div>
                    <label for="party_phone" class="block text-xs font-medium text-slate-500">{{ __('Phone number') }}</label>
                    <x-text-input id="party_phone" name="phone" type="tel" inputmode="tel"
                                  class="mt-1 block w-full !py-3 !text-base rounded-xl"
                                  :value="old('add_party') ? old('phone') : ''" required />
                    <x-input-error class="mt-1.5" :messages="old('add_party') ? $errors->get('phone') : []" />
                </div>
                <div class="flex items-start sm:pt-5">
                    <button type="submit" class="w-full rounded-xl bg-brand-800 px-5 py-3 text-sm font-bold text-white hover:bg-brand-700">
                        {{ __('Save') }}
                    </button>
                </div>
            </div>
        </form>

        <!-- Total due across everyone on this side (all-time, company-wide) -->
        <div class="rounded-2xl bg-gradient-to-br from-brand-800 to-brand-900 p-4 sm:p-5 shadow-sm">
            <p class="text-sm font-medium text-brand-200">{{ $t['total'] }}</p>
            <p class="mt-1.5 text-2xl sm:text-3xl font-bold text-white">{{ \App\Support\Money::format($totalDue) }}</p>
            <p class="mt-1 text-xs text-brand-300">{{ $t['count'] }}</p>
        </div>

        {{-- Quick Pay / Quick Collect errors only — Add shows its own inline. --}}
        @if ($errors->any() && ! old('add_party'))
        <div class="rounded-xl bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700 ring-1 ring-rose-200">
            {{ $errors->first() }}
        </div>
        @endif

        {{-- ledgerUrl is set by the row clicked; the modal below fetches it on open.
             q (search box) and dueOnly (toggle) filter rows as you type/tap, entirely
             in the browser (every row is already on the page — no reload). --}}
        <div x-data="{
                ledgerUrl: null,
                q: '',
                dueOnly: false,
                all: @js($parties->map(fn ($p) => ['text' => mb_strtolower($p->name.' '.$p->display_name.' '.$p->phone), 'due' => $p->daily_book_due > 0])->values()),
                matches(text, hasDue) {
                    const s = this.q.trim().toLowerCase();
                    return (! this.dueOnly || hasDue) && (s === '' || text.includes(s));
                },
                get noMatch() {
                    return (this.dueOnly || this.q.trim() !== '') && ! this.all.some(p => this.matches(p.text, p.due));
                },
            }">
        @if ($parties->isNotEmpty())
        <div class="mb-3 flex items-center gap-2">
            <div class="relative min-w-0 flex-1">
                <svg class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/></svg>
                <input type="search" x-model="q" inputmode="search" autocomplete="off"
                       placeholder="{{ __('Search name or mobile') }}"
                       class="block w-full rounded-xl border-slate-300 py-3 pl-12 pr-4 text-base shadow-sm focus:border-accent-500 focus:ring-accent-500">
            </div>
            <button type="button" x-on:click="dueOnly = ! dueOnly" x-bind:aria-pressed="dueOnly.toString()"
                    class="shrink-0 rounded-xl px-4 py-3 text-sm font-semibold ring-1 transition-colors"
                    x-bind:class="dueOnly ? 'bg-rose-600 text-white ring-rose-600' : 'bg-white text-slate-600 ring-slate-300 hover:bg-slate-50'">
                {{ __('Due only') }}
            </button>
        </div>
        @endif
        <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 divide-y divide-slate-100">
            {{-- :hidden (not x-show) on these rows: Tailwind's divide-y skips
                 [hidden] siblings, so filtering never leaves a stray top border. --}}
            <p hidden x-bind:hidden="! noMatch" class="px-5 py-10 text-center text-sm text-slate-400">
                <span x-show="q.trim() !== ''">{{ __('No one matches that name or number.') }}</span>
                <span x-show="q.trim() === ''">{{ __('No one has a due right now.') }}</span>
            </p>
            @forelse ($parties as $party)
            {{-- Reopen the settle form that failed validation, so the owner sees what to fix. --}}
            @php $reopen = (int) old('settle_party_id') === $party->id; @endphp
            <div x-data="{
                    settling: @js($reopen),
                    due: @js((float) $party->daily_book_due),
                    amt: @js($reopen ? old('amount', '') : ''),
                    waive: @js($reopen && (bool) old('waive_rest')),
                    {{-- What 'waive the rest' would write off: the due left after this payment. --}}
                    get waiveAmount() {
                        const left = this.due - (parseFloat(this.amt) || 0);
                        return left > 0 ? left : 0;
                    },
                }"
                 x-bind:hidden="! matches(@js(mb_strtolower($party->name.' '.$party->display_name.' '.$party->phone)), @js($party->daily_book_due > 0))"
                 class="p-4 sm:px-5">
                {{-- Clicking the row opens this party's ledger; the settle button and
                     the phone link stop the click so they keep doing their own job. --}}
                <div role="button" tabindex="0"
                     x-on:click="ledgerUrl = @js(route($routeBase.'.ledger', $party)); $dispatch('open-modal', 'party-ledger')"
                     x-on:keydown.enter="$el.click()"
                     class="-m-2 flex cursor-pointer items-center justify-between gap-3 rounded-xl p-2 hover:bg-slate-50">
                    <div class="min-w-0">
                        <p class="flex items-center gap-2 font-semibold text-slate-800">
                            <span class="truncate">{{ $party->display_name }}</span>
                            @if ($party->isWalkIn())
                            <span class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-500">{{ __('Walk-in') }}</span>
                            @endif
                        </p>
                        <p class="text-sm text-slate-500">
                            <a href="tel:{{ $party->phone }}" x-on:click.stop class="hover:text-brand-800">{{ $party->phone }}</a>
                            <span class="text-slate-300">·</span>
                            <span class="text-xs font-semibold text-brand-700">{{ __('View ledger') }} ›</span>
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-3">
                        <div class="text-right">
                            <p class="text-[11px] uppercase tracking-wide text-slate-400">{{ __('Due') }}</p>
                            <p class="text-lg font-bold {{ $party->daily_book_due > 0 ? 'text-rose-600' : 'text-slate-300' }}">
                                {{ \App\Support\Money::format($party->daily_book_due) }}
                            </p>
                        </div>
                        @if ($party->daily_book_due > 0)
                        {{-- From sm up the button sits beside the due; phones get the
                             full-width one below, so the name isn't squeezed. --}}
                        <button type="button" x-on:click.stop="settling = ! settling"
                                class="hidden sm:block rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700"
                                x-bind:class="settling && 'bg-slate-500 hover:bg-slate-600'">
                            <span x-show="! settling">{{ $t['settle'] }}</span>
                            <span x-show="settling" x-cloak>{{ __('Close') }}</span>
                        </button>
                        @endif
                    </div>
                </div>

                @if ($party->daily_book_due > 0)
                <button type="button" x-on:click="settling = ! settling"
                        class="sm:hidden mt-3 w-full rounded-xl bg-emerald-600 py-3 text-sm font-bold text-white active:bg-emerald-700"
                        x-bind:class="settling && 'bg-slate-500 active:bg-slate-600'">
                    <span x-show="! settling">{{ $t['settle'] }}</span>
                    <span x-show="settling" x-cloak>{{ __('Close') }}</span>
                </button>
                @endif

                @if ($party->daily_book_due > 0)
                <form x-show="settling" x-cloak method="POST" action="{{ route($settleRoute, $party) }}"
                      class="mt-3 grid grid-cols-1 sm:grid-cols-[1fr_1fr_2fr_auto] gap-3 rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200">
                    @csrf
                    <input type="hidden" name="settle_party_id" value="{{ $party->id }}">
                    <div>
                        <label class="block text-xs font-medium text-slate-500">{{ __('Amount') }}</label>
                        {{-- With "waive the rest" ticked, 0 / blank is allowed: a pure write-off. --}}
                        <x-text-input name="amount" type="number" inputmode="decimal" step="0.01" max="{{ $party->daily_book_due }}"
                                      class="mt-1 block w-full !py-3 !text-base rounded-xl"
                                      x-model="amt" x-bind:min="waive ? 0 : 0.01" x-bind:required="! waive"
                                      placeholder="{{ __('Due: :due', ['due' => \App\Support\Money::format($party->daily_book_due)]) }}" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500">{{ __('Date') }}</label>
                        <x-text-input name="entry_date" type="date"
                                      class="mt-1 block w-full !py-3 !text-base rounded-xl"
                                      :value="$reopen ? old('entry_date') : today()->toDateString()" required />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500">{{ __('Note (optional)') }}</label>
                        <x-text-input name="note" type="text"
                                      class="mt-1 block w-full !py-3 !text-base rounded-xl"
                                      :value="$reopen ? old('note') : ''" placeholder="{{ $t['notePlaceholder'] }}" />
                    </div>
                    <div class="flex items-end">
                        <button type="submit" class="w-full rounded-xl bg-emerald-600 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-700">
                            {{ $t['settleSubmit'] }}
                        </button>
                    </div>
                    {{-- Write off (মাফ) whatever's left after this payment — recorded as its
                         own ledger line, so the due ends at 0. --}}
                    <label class="sm:col-span-4 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg bg-amber-50 px-3 py-2.5 ring-1 ring-amber-200 cursor-pointer">
                        <span class="flex items-center gap-2">
                            <input type="checkbox" name="waive_rest" value="1" x-model="waive"
                                   class="h-5 w-5 rounded border-amber-300 text-amber-600 focus:ring-amber-500">
                            <span class="text-sm font-semibold text-amber-900">{{ $t['waive'] }}</span>
                        </span>
                        <span x-show="waive && waiveAmount > 0" x-cloak class="text-sm text-amber-800">
                            — <span class="font-bold" x-text="'৳' + waiveAmount.toFixed(2)"></span> {{ __('will be waived') }}
                        </span>
                    </label>
                </form>
                @endif
            </div>
            @empty
            <div class="px-5 py-10 text-center text-sm text-slate-400">{{ $t['empty'] }}</div>
            @endforelse
        </div>

        {{-- Ledger modal. x-modal remounts its slot on every open, so this fetch
             runs fresh each time for whichever row set ledgerUrl. --}}
        <x-modal name="party-ledger" maxWidth="2xl">
            <div class="relative" x-data="{ html: '', loading: true, failed: false }"
                 x-init="fetch(ledgerUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                            .then(r => r.ok ? r.text() : Promise.reject(r))
                            .then(t => { html = t; loading = false })
                            .catch(() => { failed = true; loading = false })">
                <button type="button" x-on:click="$dispatch('close-modal', 'party-ledger')"
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

        <p class="text-xs text-slate-400 px-1">{{ $t['footer'] }}</p>
    </div>
</x-app-layout>
