@php
    $typeLabels = [
        'purchase' => __('Purchase'),
        'sale' => __('Sale'),
        'expense' => __('Expense'),
        'capital' => __('Capital'),
    ];
    $typeLabel = $typeLabels[$type] ?? ucfirst($type);
@endphp
<x-app-layout>
    <x-slot name="title">{{ __(':type Entry', ['type' => $typeLabel]) }}</x-slot>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-brand-900">{{ __('Daily Book — :type Entry', ['type' => $typeLabel]) }}</h2>
                <p class="text-sm text-slate-500 mt-0.5">
                    {{ __('Quick daily log — no product lines, no ledger posting, no stock effect.') }}
                </p>
            </div>
            <a href="{{ route('daily-book.entries.create', $type) }}"
               class="flex w-full sm:w-auto items-center justify-center gap-2 rounded-lg bg-brand-800 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                {{ __('New :type Entry', ['type' => $typeLabel]) }}
            </a>
        </div>
    </x-slot>

    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full min-w-[680px] text-sm">
            <thead>
                <tr class="border-b border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <th class="px-5 py-3 font-semibold">{{ __('Date') }}</th>
                    {{-- Site hidden for Daily Book, not removed — same as the hidden
                         Site picker on the entry form. --}}
                    <th class="hidden px-5 py-3 font-semibold">{{ __('Site') }}</th>
                    @if ($side)
                    <th class="px-5 py-3 font-semibold">{{ $side === 'customer' ? __('Customer') : __('Supplier') }}</th>
                    @endif
                    <th class="px-5 py-3 font-semibold">{{ __('Note') }}</th>
                    <th class="px-5 py-3 font-semibold">{{ __('Photo') }}</th>
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Amount') }}</th>
                    @if ($side)
                    <th class="px-5 py-3 font-semibold text-right">{{ __('Due') }}</th>
                    @endif
                    <th class="px-5 py-3 font-semibold">{{ __('Logged By') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($entries as $entry)
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-3 text-slate-600">{{ $entry->entry_date->translatedFormat('d M, Y') }}</td>
                    <td class="hidden px-5 py-3 text-slate-600">{{ $entry->site->name ?? '—' }}</td>
                    @if ($side)
                    <td class="px-5 py-3 text-slate-600">{{ $entry->party?->display_name ?? '—' }}</td>
                    @endif
                    <td class="px-5 py-3 text-slate-500">{{ $entry->note ?: '—' }}</td>
                    <td class="px-5 py-3">
                        @if ($entry->attachments->isNotEmpty())
                        <a href="{{ $entry->attachments->first()->url }}" target="_blank" rel="noopener">
                            <img src="{{ $entry->attachments->first()->url }}" alt="{{ __('Receipt photo') }}"
                                 class="h-10 w-10 rounded-lg object-cover ring-1 ring-slate-200 hover:ring-accent-400">
                        </a>
                        @else
                        <span class="text-slate-300">—</span>
                        @endif
                    </td>
                    <td class="px-5 py-3 text-right font-semibold text-brand-900">{{ number_format($entry->amount, 2) }}</td>
                    @if ($side)
                    <td class="px-5 py-3 text-right font-semibold {{ $entry->due_amount > 0 ? 'text-rose-600' : 'text-slate-300' }}">
                        {{ $entry->due_amount > 0 ? number_format($entry->due_amount, 2) : '—' }}
                    </td>
                    @endif
                    <td class="px-5 py-3 text-slate-500">{{ $entry->creator->name ?? '—' }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="{{ $side ? 8 : 6 }}" class="px-5 py-10 text-center text-slate-400">
                        {{ __('No :type entries logged yet.', ['type' => strtolower($typeLabel)]) }}
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
        </div>

        @if ($entries->hasPages())
        <div class="border-t border-slate-100 px-5 py-4">
            {{ $entries->links() }}
        </div>
        @endif
    </div>
</x-app-layout>
