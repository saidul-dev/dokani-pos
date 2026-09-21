<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\DailyBookEntry;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "Daily Book" Summary (docs/future-ideas.md — "Daily Book" quick daily
 * Purchase/Sale/Expense entry + Summary dashboard) — a lightweight,
 * read-only total of Purchase / Sale / Expense for day, week, or month,
 * for shop owners who just want the day's numbers without digging through
 * anything else.
 *
 * Not to be confused with DayBookController (route name "day-book.index"),
 * which is the chronological ledger-transaction register — this is a
 * three-number summary, unrelated in purpose despite the similar name.
 *
 * Sourced entirely from DailyBookEntry (the standalone quick-log table),
 * NOT from the real Purchase/Sale/Expense tables — Daily Book is meant to
 * be usable end-to-end before a shop ever touches the full itemized
 * system, so its own summary can't depend on that system having any data
 * in it. See docs/future-ideas.md's "Daily Book" entry for why the two
 * stay disconnected.
 */
class DailyBookController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:daily-book.view'),
            new Middleware('permission:daily-book.edit', only: ['editSettings', 'updateSettings']),
        ];
    }

    public function summary(Request $request)
    {
        $siteId = Auth::user()->current_site_id;
        $range = $request->get('range', 'day');

        if (! in_array($range, ['day', 'week', 'month', 'custom'], true)) {
            $range = 'day';
        }

        if ($range === 'custom') {
            // Falls back to today on a bare/first visit to ?range=custom
            // (no from/to yet) rather than erroring — same convention as
            // PurchaseReportController's date filters.
            $from = $request->filled('from') ? $request->date('from') : today();
            $to = $request->filled('to') ? $request->date('to') : today();

            // A backwards range (to before from) would silently return
            // nothing from the whereDate BETWEEN-style filters below, which
            // reads as a bug rather than an empty result — swap instead.
            if ($to->lt($from)) {
                [$from, $to] = [$to, $from];
            }

            $label = $from->equalTo($to)
                ? $from->format('d M Y')
                : $from->format('d M Y').' – '.$to->format('d M Y');
        } else {
            [$from, $to, $label] = match ($range) {
                'week' => [now()->startOfWeek(), now()->endOfWeek(), __('This Week')],
                'month' => [now()->startOfMonth(), now()->endOfMonth(), __('This Month')],
                default => [today(), today(), __('Today')],
            };
        }

        $baseQuery = fn (string $type) => DailyBookEntry::where('type', $type)
            ->whereDate('entry_date', '>=', $from)
            ->whereDate('entry_date', '<=', $to)
            // Expense has no site concept even in the real Expense table
            // (see its model) — kept company-wide here too, for the same
            // reason. Purchase/Sale entries do carry a site, scoped like
            // the rest of the app.
            ->when($siteId && $type !== 'expense', fn ($q) => $q->where('site_id', $siteId));

        $totalPurchase = (float) $baseQuery('purchase')->sum('amount');
        $totalSale = (float) $baseQuery('sale')->sum('amount');
        $totalExpense = (float) $baseQuery('expense')->sum('amount');

        // Sale − Purchase: Purchase stands in for cost of goods sold here
        // (Daily Book has no per-product cost to subtract instead), and
        // Expense is deliberately left out — it's an operating cost, not a
        // cost of the goods themselves, same distinction as gross vs. net
        // profit in a real P&L. Unlike Estimated Profit below, this is a
        // real number straight from the logged entries, not a guess.
        $grossProfit = $totalSale - $totalPurchase;

        // Net Profit = Gross Profit − Expense, the standard next line down
        // from Gross Profit in any P&L. Still a real number (no margin %
        // guesswork involved) — Estimated Profit below is the separate,
        // approximate figure.
        $netProfit = $grossProfit - $totalExpense;

        $marginPercent = CompanySetting::current()->daily_book_profit_margin_percent;

        // Rough estimate only — Daily Book has no per-product cost, so this
        // is "Sale × the owner's own guessed margin %", never a real
        // cost-based profit. Null (not yet configured) is kept distinct
        // from 0% — see Settings below and the view, which must not treat
        // "not set" as "0% margin".
        $estimatedProfit = $marginPercent !== null
            ? $totalSale * ((float) $marginPercent / 100)
            : null;

        return view('admin.daily-book.summary', [
            'range' => $range,
            'rangeLabel' => $label,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totalPurchase' => $totalPurchase,
            'totalSale' => $totalSale,
            'totalExpense' => $totalExpense,
            'grossProfit' => $grossProfit,
            'netProfit' => $netProfit,
            'marginPercent' => $marginPercent,
            'estimatedProfit' => $estimatedProfit,
            'cashInHand' => $this->cashInHand($siteId),
        ]);
    }

    /**
     * Total Capital + Total Sale − Total Purchase − Total Expense, across
     * ALL TIME — deliberately not scoped to the Summary page's selected
     * day/week/month/custom range, since "cash in hand" only makes sense
     * as a running balance from the first entry ever logged, not as a
     * per-period figure. 'capital' entries are the owner putting money
     * into the business (see DailyBookEntry) — there's no separate
     * "starting balance"; the first capital entry effectively is one.
     */
    protected function cashInHand(?int $siteId): float
    {
        $allTime = fn (string $type) => DailyBookEntry::where('type', $type)
            ->when($siteId && $type !== 'expense', fn ($q) => $q->where('site_id', $siteId))
            ->sum('amount');

        return (float) $allTime('capital')
            + (float) $allTime('sale')
            - (float) $allTime('purchase')
            - (float) $allTime('expense');
    }

    /**
     * The Daily Book's one setting: an approximate, shop-wide profit
     * margin % (see the migration for why this can only ever be a guess,
     * not a real cost-based figure). Gated behind daily-book.edit, not
     * .view — deliberately more restrictive than Summary/Entry, since this
     * assumption changes what "profit" means on every Summary view.
     */
    public function editSettings()
    {
        return view('admin.daily-book.settings', [
            'company' => CompanySetting::current(),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'daily_book_profit_margin_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        CompanySetting::current()->update([
            'daily_book_profit_margin_percent' => $validated['daily_book_profit_margin_percent'] ?? null,
        ]);

        return redirect()->route('daily-book.settings.edit')
            ->with('success', __('Daily Book settings updated.'));
    }

    /**
     * One shared form + list per entry type ('purchase' | 'sale' |
     * 'expense') — see DailyBookEntry. Quick log only: a plain amount +
     * date + note, no product lines, no ledger posting, no stock effect.
     */
    public function entryIndex(string $type)
    {
        $this->assertValidType($type);

        $entries = DailyBookEntry::with(['site', 'attachments'])
            ->where('type', $type)
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate(30);

        return view('admin.daily-book.entry-index', [
            'type' => $type,
            'entries' => $entries,
        ]);
    }

    public function entryCreate(string $type)
    {
        $this->assertValidType($type);

        return view('admin.daily-book.entry-create', [
            'type' => $type,
            'sites' => Site::where('status', true)->orderBy('name')->get(),
        ]);
    }

    public function entryStore(Request $request, string $type)
    {
        $this->assertValidType($type);

        $validated = $request->validate([
            'entry_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'site_id' => ['nullable', 'exists:sites,id'],
            'note' => ['nullable', 'string', 'max:2000'],
            // Optional receipt/bill photo — phone camera capture (see the
            // `capture` attribute on the file input) or a normal file pick.
            // Not required: the whole point of Daily Book is zero-friction
            // logging, so a missing photo must never block saving.
            'photo' => ['nullable', 'image', 'max:8192'],
        ]);

        $entry = DailyBookEntry::create([
            'type' => $type,
            'site_id' => $validated['site_id'] ?? Auth::user()->current_site_id,
            'entry_date' => $validated['entry_date'],
            'amount' => $validated['amount'],
            'note' => $validated['note'] ?? null,
            'created_by' => Auth::id(),
        ]);

        if ($request->hasFile('photo')) {
            $file = $request->file('photo');

            $entry->attachments()->create([
                'label' => $file->getClientOriginalName(),
                'path' => $file->store('daily-book', 'public'),
                'original_name' => $file->getClientOriginalName(),
                'uploaded_by' => Auth::id(),
            ]);
        }

        return redirect()->route('daily-book.entries.index', $type)
            ->with('success', __(':type entry saved.', ['type' => ucfirst($type)]));
    }

    protected function assertValidType(string $type): void
    {
        if (! in_array($type, DailyBookEntry::TYPES, true)) {
            throw new NotFoundHttpException();
        }
    }
}
