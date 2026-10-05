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
                <h2 class="text-xl sm:text-2xl font-bold text-brand-900">{{ __('Daily Book — :type Entry', ['type' => $typeLabel]) }}</h2>
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
        {{-- Phones: one card per entry (an app-style list — the table's 6+ columns
             don't fit a 390px screen). From sm up: the table below. --}}
        <ul class="sm:hidden divide-y divide-slate-100">
            @forelse ($entries as $entry)
            <li class="flex items-center gap-3 px-4 py-3">
                @if ($entry->attachments->isNotEmpty())
                <a href="{{ $entry->attachments->first()->url }}" target="_blank" rel="noopener" class="shrink-0">
                    <img src="{{ $entry->attachments->first()->url }}" alt="{{ __('Receipt photo') }}"
                         class="h-12 w-12 rounded-xl object-cover ring-1 ring-slate-200">
                </a>
                @else
                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-slate-100 text-slate-400">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z"/></svg>
                </span>
                @endif
                <div class="min-w-0 flex-1">
                    <p class="truncate font-semibold text-slate-800">
                        {{ $side ? ($entry->party?->display_name ?? '—') : ($entry->note ?: $typeLabel) }}
                    </p>
                    <p class="truncate text-xs text-slate-500">
                        {{ $entry->entry_date->translatedFormat('d M, Y') }}
                        @if ($side && $entry->note) · {{ $entry->note }} @endif
                    </p>
                </div>
                <div class="shrink-0 text-right">
                    <p class="font-bold text-brand-900">{{ number_format($entry->amount, 2) }}</p>
                    @if ($side && $entry->due_amount > 0)
                    <p class="mt-0.5 inline-block rounded-full bg-rose-50 px-2 py-0.5 text-[11px] font-semibold text-rose-600">
                        {{ __('Due') }} {{ number_format($entry->due_amount, 2) }}
                    </p>
                    @endif
                </div>
            </li>
            @empty
            <li class="px-4 py-10 text-center text-sm text-slate-400">
                {{ __('No :type entries logged yet.', ['type' => strtolower($typeLabel)]) }}
            </li>
            @endforelse
        </ul>

        <div class="hidden sm:block overflow-x-auto">
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
