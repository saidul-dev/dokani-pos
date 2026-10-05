@php
    $typeLabels = [
        'purchase' => __('Purchase'),
        'sale' => __('Sale'),
        'expense' => __('Expense'),
        'capital' => __('Capital'),
    ];
    $typeLabel = $typeLabels[$type] ?? ucfirst($type);

    $notePlaceholders = [
        'purchase' => __('e.g. Rin Powder, Lux Soap, rice…'),
        'sale' => __('e.g. Rin Powder, Lux Soap, rice…'),
        'expense' => __('e.g. Shop rent, electricity bill, van fare…'),
        'capital' => __('e.g. Owner investment, loan from family…'),
    ];
    $notePlaceholder = $notePlaceholders[$type] ?? '';

    // Purchase (supplier) and Sale (customer) share the party + paid fields —
    // only the wording differs. A sale with no customer picked is a walk-in.
    $p = $side === 'customer' ? [
        'party' => __('Customer'),
        'new' => __('New Customer'),
        'none' => __('— Walk-in Customer —'),
        'name' => __('Customer name'),
        'help' => __('Leave as Walk-in unless they\'re buying on credit.'),
        'paid' => __('Received now'),
        'paidHint' => __('Type how much you received now — 0 if you received nothing.'),
        'paidFull' => __('Received in full.'),
    ] : [
        'party' => __('Supplier'),
        'new' => __('New Supplier'),
        'none' => __('— No supplier —'),
        'name' => __('Supplier name'),
        'help' => __('The supplier / wholesaler you bought from.'),
        'paid' => __('Paid now'),
        'paidHint' => __('Type how much you paid now — 0 if you paid nothing.'),
        'paidFull' => __('Paid in full.'),
    ];
@endphp
<x-app-layout>
    <x-slot name="title">{{ __(':type Entry', ['type' => $typeLabel]) }}</x-slot>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-bold text-brand-900">{{ __('New :type Entry', ['type' => $typeLabel]) }}</h2>
            <p class="text-sm text-slate-500 mt-0.5">
                {{ __('A quick daily log — just the amount, no product lines. This does not affect Accounts or Stock.') }}
            </p>
        </div>
    </x-slot>

    <div class="w-full" x-data="{
            photoName: null,
            photoUrl: null,
            {{-- Purchase/Sale only: the owner types Paid/Received themselves (or taps
                 Full amount) — it never fills in on its own. Anything less than Amount
                 is a due. --}}
            amount: @js(old('amount', '')),
            paid: @js(old('paid_amount', '')),
            newParty: @js(filled(old('new_party_name')) || filled(old('new_party_phone'))),
            get paidEntered() {
                return this.paid !== '' && this.paid !== null;
            },
            {{-- No due until Paid is actually typed — an empty Paid is "not
                 answered yet", not "paid nothing". --}}
            get due() {
                if (! this.paidEntered) return 0;
                const d = (parseFloat(this.amount) || 0) - (parseFloat(this.paid) || 0);
                return d > 0 ? d : 0;
            },
        }">
        <form method="POST" action="{{ route('daily-book.entries.store', $type) }}" enctype="multipart/form-data"
              class="w-full rounded-2xl bg-white p-4 sm:p-6 shadow-sm ring-1 ring-slate-200 space-y-6 pb-28 sm:pb-6">
            @csrf

            {{-- Two columns from sm up, stacked on phones. Purchase/Sale: Date |
                 Supplier/Customer, then Amount | Paid/Received now. Other types: just
                 Date | Amount. Every label row is the same height (h-7) so
                 side-by-side inputs line up even when one label carries a button. --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <div class="flex h-7 items-center">
                        <x-input-label for="entry_date" :value="__('Date')" class="text-sm font-semibold" />
                    </div>
                    <x-text-input id="entry_date" name="entry_date" type="date"
                                  class="mt-1.5 block w-full !py-3.5 !text-base rounded-xl"
                                  :value="old('entry_date', today()->toDateString())" required />
                    <x-input-error class="mt-2" :messages="$errors->get('entry_date')" />
                </div>

                @if ($side)
                {{-- Credit purchase from a supplier / credit sale to a customer (a
                     Party with is_supplier / is_customer). Optional when fully
                     paid/received (a sale then goes to the Walk-in Customer),
                     required the moment any due is kept — enforced again
                     server-side. --}}
                <div>
                    <div class="flex h-7 items-center justify-between gap-3">
                        <x-input-label for="party_id" class="text-sm font-semibold">
                            {{ $p['party'] }}
                            <span class="font-normal text-slate-400" x-show="due <= 0">({{ __('optional') }})</span>
                            <span class="font-semibold text-rose-600" x-show="due > 0" x-cloak>({{ __('required for due') }})</span>
                        </x-input-label>
                        <button type="button" x-on:click="newParty = ! newParty"
                                class="shrink-0 rounded-lg px-2.5 py-1 text-xs font-semibold text-brand-800 ring-1 ring-slate-200 bg-white hover:bg-slate-50">
                            <span x-show="! newParty">+ {{ $p['new'] }}</span>
                            <span x-show="newParty" x-cloak>{{ __('Choose existing') }}</span>
                        </button>
                    </div>

                    {{-- Disabled inputs aren't submitted, so only the active mode
                         (pick existing vs. quick add) reaches the server. --}}
                    <div x-show="! newParty" class="mt-1.5">
                        <select id="party_id" name="party_id" x-bind:disabled="newParty"
                                class="block w-full rounded-xl border-slate-300 !py-3.5 !text-base focus:border-accent-500 focus:ring-accent-500">
                            <option value="">{{ $p['none'] }}</option>
                            @foreach ($parties as $party)
                            <option value="{{ $party->id }}" @selected(old('party_id') == $party->id)>{{ $party->name }} · {{ $party->phone }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div x-show="newParty" x-cloak class="mt-1.5 grid grid-cols-2 gap-3">
                        <x-text-input name="new_party_name" type="text" x-bind:disabled="! newParty"
                                      class="block w-full !py-3.5 !text-base rounded-xl"
                                      :value="old('new_party_name')" placeholder="{{ $p['name'] }}" />
                        <x-text-input name="new_party_phone" type="tel" inputmode="tel" x-bind:disabled="! newParty"
                                      class="block w-full !py-3.5 !text-base rounded-xl"
                                      :value="old('new_party_phone')" placeholder="{{ __('Phone number') }}" />
                    </div>
                    <p class="mt-1.5 text-xs text-slate-400">{{ $p['help'] }}</p>
                    <x-input-error class="mt-2" :messages="$errors->get('party_id')" />
                    <x-input-error class="mt-2" :messages="$errors->get('new_party_name')" />
                    <x-input-error class="mt-2" :messages="$errors->get('new_party_phone')" />
                </div>
                @endif

                <div>
                    <div class="flex h-7 items-center">
                        <x-input-label for="amount" :value="__('Amount')" class="text-sm font-semibold" />
                    </div>
                    <div class="relative mt-1.5">
                        <span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-base font-semibold text-slate-400">{{ __('৳') }}</span>
                        <x-text-input id="amount" name="amount" type="number" inputmode="decimal" step="0.01" min="0.01"
                                      class="block w-full !py-3.5 !pl-9 !text-base rounded-xl"
                                      x-model="amount"
                                      placeholder="0.00" required autofocus />
                    </div>
                    <x-input-error class="mt-2" :messages="$errors->get('amount')" />
                </div>

                @if ($side)
                <div>
                    <div class="flex h-7 items-center">
                        <x-input-label for="paid_amount" :value="$p['paid']" class="text-sm font-semibold" />
                    </div>
                    <div class="relative mt-1.5">
                        <span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-base font-semibold text-slate-400">{{ __('৳') }}</span>
                        <x-text-input id="paid_amount" name="paid_amount" type="number" inputmode="decimal" step="0.01" min="0"
                                      class="block w-full !py-3.5 !pl-9 !pr-32 !text-base rounded-xl"
                                      x-model="paid"
                                      placeholder="0.00" required />
                        {{-- One-off copy of Amount into Paid — not a link; a later Amount
                             edit won't change Paid. --}}
                        <button type="button" x-on:click="paid = amount"
                                x-bind:disabled="! amount"
                                class="absolute inset-y-1.5 right-1.5 rounded-lg bg-brand-800 px-3 text-xs font-semibold text-white hover:bg-brand-700 disabled:cursor-not-allowed disabled:bg-slate-300">
                            {{ __('Full amount') }}
                        </button>
                    </div>
                    <p class="mt-1.5 text-sm text-rose-600" x-show="due > 0" x-cloak>
                        {{ __('Due') }}: <span class="font-bold" x-text="'৳' + due.toFixed(2)"></span>
                    </p>
                    <p class="mt-1.5 text-xs text-slate-400" x-show="! paidEntered">{{ $p['paidHint'] }}</p>
                    <p class="mt-1.5 text-xs text-slate-400" x-show="paidEntered && due <= 0" x-cloak>{{ $p['paidFull'] }}</p>
                    <x-input-error class="mt-2" :messages="$errors->get('paid_amount')" />
                </div>
                @endif
            </div>

            {{-- Site picker hidden for Daily Book — keep the field/backend logic
                 intact (see DailyBookController::entryStore, which falls back to
                 the user's current_site_id), just don't show it here; this stays
                 a quick, no-decisions-to-make log. --}}
            @if ($sites->count() > 1)
            <div class="hidden">
                <x-input-label for="site_id" :value="__('Site')" class="text-sm font-semibold" />
                <select id="site_id" name="site_id"
                        class="mt-1.5 block w-full rounded-xl border-slate-300 !py-3.5 !text-base focus:border-accent-500 focus:ring-accent-500">
                    <option value="">{{ __('— Use my current site —') }}</option>
                    @foreach ($sites as $site)
                    <option value="{{ $site->id }}" @selected(old('site_id') == $site->id)>{{ $site->name }}</option>
                    @endforeach
                </select>
                <x-input-error class="mt-2" :messages="$errors->get('site_id')" />
            </div>
            @endif

            <div>
                <x-input-label for="note" :value="__('Note (optional)')" class="text-sm font-semibold" />
                <textarea id="note" name="note" rows="3"
                          class="mt-1.5 block w-full rounded-xl border-slate-300 !text-base focus:border-accent-500 focus:ring-accent-500"
                          placeholder="{{ $notePlaceholder }}">{{ old('note') }}</textarea>
                <x-input-error class="mt-2" :messages="$errors->get('note')" />
            </div>

            <div>
                <x-input-label :value="__('Photo (optional)')" class="text-sm font-semibold" />
                <p class="mt-0.5 text-xs text-slate-400">{{ __('A receipt or bill photo — snap it with your phone camera, or pick a file.') }}</p>

                <div class="mt-2" x-show="!photoUrl">
                    <label for="photo"
                           class="flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center cursor-pointer hover:border-accent-400 hover:bg-accent-50/40 transition-colors">
                        <svg class="h-8 w-8 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574v9.176c0 1.24 1.01 2.25 2.25 2.25h15c1.24 0 2.25-1.01 2.25-2.25V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z" />
                        </svg>
                        <span class="text-sm font-semibold text-brand-800">{{ __('Take or choose a photo') }}</span>
                        <span class="text-xs text-slate-400">{{ __('JPG or PNG, up to 8MB') }}</span>
                    </label>
                    <input id="photo" name="photo" type="file" accept="image/*" capture="environment" class="hidden"
                           @change="
                               const f = $event.target.files[0];
                               if (f) { photoName = f.name; photoUrl = URL.createObjectURL(f); }
                           ">
                </div>

                <div x-show="photoUrl" x-cloak class="mt-2 flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <img :src="photoUrl" class="h-16 w-16 shrink-0 rounded-lg object-cover ring-1 ring-slate-200">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-slate-700" x-text="photoName"></p>
                        <p class="text-xs text-slate-400">{{ __('Ready to upload') }}</p>
                    </div>
                    <button type="button"
                            @click="photoUrl = null; photoName = null; document.getElementById('photo').value = ''"
                            class="shrink-0 rounded-lg px-3 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-50">
                        {{ __('Remove') }}
                    </button>
                </div>
                <x-input-error class="mt-2" :messages="$errors->get('photo')" />
            </div>

            <!-- Desktop actions -->
            <div class="hidden sm:flex items-center justify-end gap-3 pt-2">
                <a href="{{ route('daily-book.entries.index', $type) }}" class="text-sm font-semibold text-slate-500 hover:text-slate-700">
                    {{ __('Cancel') }}
                </a>
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-brand-800 px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-700 focus:ring-2 focus:ring-accent-500 focus:ring-offset-2">
                    {{ __('Save Entry') }}
                </button>
            </div>

            <!-- Mobile: sticky bottom action bar, app-style -->
            <div class="sm:hidden fixed inset-x-0 bottom-0 z-40 border-t border-slate-200 bg-white/95 backdrop-blur px-4 pt-3"
                 style="padding-bottom: max(0.75rem, env(safe-area-inset-bottom));">
                <div class="flex items-center gap-3">
                    <a href="{{ route('daily-book.entries.index', $type) }}"
                       class="flex-1 rounded-xl px-4 py-3.5 text-center text-sm font-semibold text-slate-500 ring-1 ring-slate-200">
                        {{ __('Cancel') }}
                    </a>
                    <button type="submit"
                            class="flex-[2] rounded-xl bg-brand-800 px-4 py-3.5 text-center text-sm font-bold text-white shadow-sm active:bg-brand-900">
                        {{ __('Save Entry') }}
                    </button>
                </div>
            </div>
        </form>
    </div>
</x-app-layout>
