{{-- Bare partial, fetched into the ledger modal on the Suppliers / Customers list
     (DailyBookController::partyLedger). Static HTML only — the modal's close
     button and loading state live in party-index.blade.php. --}}
@php
    $isCustomer = $side === 'customer';
    $t = $isCustomer ? [
        'heading' => __('Customer Ledger'),
        'billedTotal' => __('Total Sale'),
        'settledTotal' => __('Total Received'),
        'billedCol' => __('Sale'),
        'settledCol' => __('Received'),
        'entryBadge' => __('Sale'),
        'settleBadge' => __('Collection'),
        'empty' => __('No transactions with this customer yet.'),
        'hint' => __('Oldest first. Balance is what they still owe after each line.'),
    ] : [
        'heading' => __('Supplier Ledger'),
        'billedTotal' => __('Total Purchase'),
        'settledTotal' => __('Total Paid'),
        'billedCol' => __('Purchase'),
        'settledCol' => __('Paid'),
        'entryBadge' => __('Purchase'),
        'settleBadge' => __('Payment'),
        'empty' => __('No transactions with this supplier yet.'),
        'hint' => __('Oldest first. Balance is what you still owe after each line.'),
    ];
@endphp
<div class="p-4 sm:p-6">
    <div class="pr-10">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ $t['heading'] }}</p>
        <h3 class="mt-0.5 text-lg sm:text-xl font-bold text-brand-900">{{ $party->display_name }}</h3>
        <a href="tel:{{ $party->phone }}" class="text-sm text-slate-500 hover:text-brand-800">{{ $party->phone }}</a>
    </div>

    <div class="mt-4 grid grid-cols-3 gap-2 sm:gap-3">
        <div class="rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200">
            <p class="text-[11px] font-medium uppercase tracking-wide text-slate-500">{{ $t['billedTotal'] }}</p>
            <p class="mt-1 text-sm sm:text-base font-bold text-brand-900 break-words">{{ \App\Support\Money::format($totalBilled) }}</p>
        </div>
        <div class="rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200">
            <p class="text-[11px] font-medium uppercase tracking-wide text-slate-500">{{ $t['settledTotal'] }}</p>
            <p class="mt-1 text-sm sm:text-base font-bold text-emerald-600 break-words">{{ \App\Support\Money::format($totalSettled) }}</p>
            @if ($totalWaived > 0)
            <p class="mt-0.5 text-[11px] font-semibold text-amber-700">+ {{ __(':amount waived', ['amount' => \App\Support\Money::format($totalWaived)]) }}</p>
            @endif
        </div>
        <div class="rounded-xl p-3 ring-1 {{ $due > 0 ? 'bg-rose-50 ring-rose-200' : 'bg-slate-50 ring-slate-200' }}">
            <p class="text-[11px] font-medium uppercase tracking-wide {{ $due > 0 ? 'text-rose-600' : 'text-slate-500' }}">{{ __('Due') }}</p>
            <p class="mt-1 text-sm sm:text-base font-bold break-words {{ $due > 0 ? 'text-rose-600' : 'text-slate-400' }}">{{ \App\Support\Money::format($due) }}</p>
        </div>
    </div>

    @if ($rows->isEmpty())
    <p class="mt-6 rounded-xl bg-slate-50 px-4 py-8 text-center text-sm text-slate-400">{{ $t['empty'] }}</p>
    @else
    {{-- Phones: one card per line (the 5-column table doesn't fit the modal at 390px).
         From sm up: the table below. --}}
    <ul class="sm:hidden mt-4 divide-y divide-slate-100 rounded-xl ring-1 ring-slate-200">
        @foreach ($rows as $row)
        <li class="px-3 py-3">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <span class="inline-block rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $row->is_entry ? 'bg-brand-50 text-brand-800' : ($row->is_waiver ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700') }}">
                        {{ $row->is_entry ? $t['entryBadge'] : ($row->is_waiver ? __('Waived') : $t['settleBadge']) }}
                    </span>
                    <span class="ml-1 text-xs text-slate-500">{{ $row->entry->entry_date->translatedFormat('d M, Y') }}</span>
                    @if ($row->entry->note)
                    <p class="mt-1 text-xs text-slate-500">{{ $row->entry->note }}</p>
                    @endif
                    @if ($row->entry->attachments->isNotEmpty())
                    <a href="{{ $row->entry->attachments->first()->url }}" target="_blank" rel="noopener"
                       class="mt-1 inline-block text-xs font-semibold text-brand-700">{{ __('Receipt photo') }}</a>
                    @endif
                </div>
                <div class="shrink-0 text-right text-sm">
                    @if ($row->is_entry)
                    <p class="font-semibold text-slate-800">{{ \App\Support\Money::format($row->billed) }}</p>
                    @if ($row->settled > 0)
                    <p class="text-xs text-emerald-600">{{ $t['settledCol'] }} {{ \App\Support\Money::format($row->settled) }}</p>
                    @endif
                    @elseif ($row->is_waiver)
                    <p class="font-semibold text-amber-700">− {{ \App\Support\Money::format($row->waived) }}</p>
                    @else
                    <p class="font-semibold text-emerald-600">− {{ \App\Support\Money::format($row->settled) }}</p>
                    @endif
                </div>
            </div>
            <p class="mt-1.5 text-right text-xs text-slate-400">
                {{ __('Balance') }}: <span class="font-semibold {{ $row->balance > 0 ? 'text-rose-600' : 'text-slate-500' }}">{{ \App\Support\Money::format($row->balance) }}</span>
            </p>
        </li>
        @endforeach
    </ul>

    <div class="hidden sm:block mt-4 overflow-x-auto rounded-xl ring-1 ring-slate-200">
        <table class="w-full min-w-[520px] text-sm">
            <thead>
                <tr class="bg-slate-50 text-left text-[11px] uppercase tracking-wide text-slate-500">
                    <th class="px-3 py-2.5 font-semibold">{{ __('Date') }}</th>
                    <th class="px-3 py-2.5 font-semibold">{{ __('Details') }}</th>
                    <th class="px-3 py-2.5 font-semibold text-right">{{ $t['billedCol'] }}</th>
                    <th class="px-3 py-2.5 font-semibold text-right">{{ $t['settledCol'] }}</th>
                    <th class="px-3 py-2.5 font-semibold text-right">{{ __('Balance') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($rows as $row)
                <tr>
                    <td class="whitespace-nowrap px-3 py-2.5 text-slate-600">{{ $row->entry->entry_date->translatedFormat('d M, Y') }}</td>
                    <td class="px-3 py-2.5">
                        <span class="inline-block rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $row->is_entry ? 'bg-brand-50 text-brand-800' : ($row->is_waiver ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700') }}">
                            {{ $row->is_entry ? $t['entryBadge'] : ($row->is_waiver ? __('Waived') : $t['settleBadge']) }}
                        </span>
                        @if ($row->entry->note)
                        <p class="mt-1 text-xs text-slate-500">{{ $row->entry->note }}</p>
                        @endif
                        @if ($row->entry->attachments->isNotEmpty())
                        <a href="{{ $row->entry->attachments->first()->url }}" target="_blank" rel="noopener"
                           class="mt-1 inline-block text-xs font-semibold text-brand-700 hover:underline">{{ __('Receipt photo') }}</a>
                        @endif
                    </td>
                    <td class="whitespace-nowrap px-3 py-2.5 text-right text-slate-700">{{ $row->billed > 0 ? \App\Support\Money::format($row->billed) : '—' }}</td>
                    {{-- A waiver clears the due without cash — shown in amber here, and kept
                         out of the Paid/Received total above. --}}
                    <td class="whitespace-nowrap px-3 py-2.5 text-right">
                        @if ($row->is_waiver)
                        <span class="text-amber-700">{{ \App\Support\Money::format($row->waived) }}</span>
                        @else
                        <span class="text-emerald-600">{{ $row->settled > 0 ? \App\Support\Money::format($row->settled) : '—' }}</span>
                        @endif
                    </td>
                    <td class="whitespace-nowrap px-3 py-2.5 text-right font-semibold {{ $row->balance > 0 ? 'text-rose-600' : 'text-slate-500' }}">{{ \App\Support\Money::format($row->balance) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="mt-2 text-xs text-slate-400">{{ $t['hint'] }}</p>
    @endif
</div>
