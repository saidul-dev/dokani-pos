{{-- Bare partial, fetched into the supplier-ledger modal on the supplier list
     (DailyBookController::supplierLedger). Static HTML only — the modal's close
     button and loading state live in supplier-index.blade.php. --}}
<div class="p-4 sm:p-6">
    <div class="pr-10">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('Supplier Ledger') }}</p>
        <h3 class="mt-0.5 text-lg sm:text-xl font-bold text-brand-900">{{ $party->name }}</h3>
        <a href="tel:{{ $party->phone }}" class="text-sm text-slate-500 hover:text-brand-800">{{ $party->phone }}</a>
    </div>

    <div class="mt-4 grid grid-cols-3 gap-2 sm:gap-3">
        <div class="rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200">
            <p class="text-[11px] font-medium uppercase tracking-wide text-slate-500">{{ __('Total Purchase') }}</p>
            <p class="mt-1 text-sm sm:text-base font-bold text-brand-900 break-words">{{ number_format($totalPurchased, 2) }}</p>
        </div>
        <div class="rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200">
            <p class="text-[11px] font-medium uppercase tracking-wide text-slate-500">{{ __('Total Paid') }}</p>
            <p class="mt-1 text-sm sm:text-base font-bold text-emerald-600 break-words">{{ number_format($totalPaid, 2) }}</p>
        </div>
        <div class="rounded-xl p-3 ring-1 {{ $due > 0 ? 'bg-rose-50 ring-rose-200' : 'bg-slate-50 ring-slate-200' }}">
            <p class="text-[11px] font-medium uppercase tracking-wide {{ $due > 0 ? 'text-rose-600' : 'text-slate-500' }}">{{ __('Due') }}</p>
            <p class="mt-1 text-sm sm:text-base font-bold break-words {{ $due > 0 ? 'text-rose-600' : 'text-slate-400' }}">{{ number_format($due, 2) }}</p>
        </div>
    </div>

    @if ($rows->isEmpty())
    <p class="mt-6 rounded-xl bg-slate-50 px-4 py-8 text-center text-sm text-slate-400">
        {{ __('No transactions with this supplier yet.') }}
    </p>
    @else
    <div class="mt-4 overflow-x-auto rounded-xl ring-1 ring-slate-200">
        <table class="w-full min-w-[520px] text-sm">
            <thead>
                <tr class="bg-slate-50 text-left text-[11px] uppercase tracking-wide text-slate-500">
                    <th class="px-3 py-2.5 font-semibold">{{ __('Date') }}</th>
                    <th class="px-3 py-2.5 font-semibold">{{ __('Details') }}</th>
                    <th class="px-3 py-2.5 font-semibold text-right">{{ __('Purchase') }}</th>
                    <th class="px-3 py-2.5 font-semibold text-right">{{ __('Paid') }}</th>
                    <th class="px-3 py-2.5 font-semibold text-right">{{ __('Balance') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($rows as $row)
                <tr>
                    <td class="whitespace-nowrap px-3 py-2.5 text-slate-600">{{ $row->entry->entry_date->format('d M, Y') }}</td>
                    <td class="px-3 py-2.5">
                        <span class="inline-block rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $row->is_purchase ? 'bg-brand-50 text-brand-800' : 'bg-emerald-50 text-emerald-700' }}">
                            {{ $row->is_purchase ? __('Purchase') : __('Payment') }}
                        </span>
                        @if ($row->entry->note)
                        <p class="mt-1 text-xs text-slate-500">{{ $row->entry->note }}</p>
                        @endif
                        @if ($row->entry->attachments->isNotEmpty())
                        <a href="{{ $row->entry->attachments->first()->url }}" target="_blank" rel="noopener"
                           class="mt-1 inline-block text-xs font-semibold text-brand-700 hover:underline">{{ __('Receipt photo') }}</a>
                        @endif
                    </td>
                    <td class="whitespace-nowrap px-3 py-2.5 text-right text-slate-700">{{ $row->purchased > 0 ? number_format($row->purchased, 2) : '—' }}</td>
                    <td class="whitespace-nowrap px-3 py-2.5 text-right text-emerald-600">{{ $row->paid > 0 ? number_format($row->paid, 2) : '—' }}</td>
                    <td class="whitespace-nowrap px-3 py-2.5 text-right font-semibold {{ $row->balance > 0 ? 'text-rose-600' : 'text-slate-500' }}">{{ number_format($row->balance, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="mt-2 text-xs text-slate-400">{{ __('Oldest first. Balance is what you still owe after each line.') }}</p>
    @endif
</div>
