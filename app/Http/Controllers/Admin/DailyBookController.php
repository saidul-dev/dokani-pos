<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\DailyBookEntry;
use App\Models\Party;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

        $marginPercent = CompanySetting::current()->daily_book_profit_margin_percent;

        // Purchase can't be used as cost of goods sold: stock that's been
        // bought but not yet sold is still the shop's inventory, not a loss
        // (buying ৳20,000 of goods and selling none is ৳0 profit, not
        // −৳20,000). Daily Book has no per-item cost to work out what the
        // sold goods actually cost, so the owner's own margin % (Settings)
        // is the only cost basis available: Gross Profit = Sale × margin %.
        // Null margin means it can't be computed yet — kept distinct from
        // 0%, and the view prompts to set it rather than showing 0.
        $grossProfit = $marginPercent !== null
            ? $totalSale * ((float) $marginPercent / 100)
            : null;

        // Expense is an operating cost, not a cost of the goods, so it comes
        // off at the Net line — same gross vs. net split as a real P&L.
        $netProfit = $grossProfit !== null ? $grossProfit - $totalExpense : null;

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
            'cashInHand' => $this->cashInHand($siteId),
        ]);
    }

    /**
     * Capital + Sale − cash actually paid out (purchases' paid_amount,
     * expenses, supplier payments), across ALL TIME — deliberately not scoped
     * to the Summary page's selected range, since "cash in hand" only makes
     * sense as a running balance from the first entry ever logged. A
     * purchase on credit only takes out what was paid at the time; the rest
     * leaves the till later, as supplier payments. 'capital' entries are the
     * owner putting money in (see DailyBookEntry) — the first one
     * effectively is the starting balance.
     */
    protected function cashInHand(?int $siteId): float
    {
        $allTime = fn (string $type, string $column = 'amount') => (float) DailyBookEntry::where('type', $type)
            ->when($siteId && $type !== 'expense', fn ($q) => $q->where('site_id', $siteId))
            ->sum($column);

        return $allTime('capital')
            + $allTime('sale')
            - $allTime('purchase', 'paid_amount')
            - $allTime('expense')
            - $allTime(DailyBookEntry::SUPPLIER_PAYMENT);
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

        $entries = DailyBookEntry::with(['site', 'attachments', 'party'])
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
            'suppliers' => $type === 'purchase'
                ? Party::where('is_supplier', true)->where('status', true)->orderBy('name')->get(['id', 'name', 'phone'])
                : collect(),
        ]);
    }

    public function entryStore(Request $request, string $type)
    {
        $this->assertValidType($type);

        $isPurchase = $type === 'purchase';

        $rules = [
            'entry_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'site_id' => ['nullable', 'exists:sites,id'],
            'note' => ['nullable', 'string', 'max:2000'],
            // Optional receipt/bill photo — phone camera capture (see the
            // `capture` attribute on the file input) or a normal file pick.
            // Not required: the whole point of Daily Book is zero-friction
            // logging, so a missing photo must never block saving.
            'photo' => ['nullable', 'image', 'max:8192'],
        ];

        if ($isPurchase) {
            $rules += [
                // Typed by the owner (or "Full amount" on the form) — never
                // assumed, so a forgotten field can't silently record a
                // credit purchase as paid. Anything less than amount is a due.
                'paid_amount' => ['required', 'numeric', 'min:0', 'lte:amount'],
                'party_id' => ['nullable', Rule::exists('parties', 'id')->where('is_supplier', true)],
                // Quick add — just name + phone, the same two things a
                // shop owner writes in their khata for a new Supplier.
                'new_party_name' => ['nullable', 'required_with:new_party_phone', 'string', 'max:255'],
                'new_party_phone' => ['nullable', 'required_with:new_party_name', 'string', 'max:30'],
            ];
        }

        $validated = $request->validate($rules, [
            'paid_amount.required' => __('Enter how much you paid now — 0 if you paid nothing.'),
            'paid_amount.lte' => __('Paid amount can\'t be more than the purchase amount.'),
        ]);

        $hasSupplier = ! empty($validated['party_id']) || ! empty($validated['new_party_name']);

        if ($isPurchase && ! $hasSupplier && (float) $validated['paid_amount'] < (float) $validated['amount']) {
            throw ValidationException::withMessages([
                'party_id' => __('To keep a due, choose or add the supplier you owe.'),
            ]);
        }

        $reusedParty = null;

        $entry = DB::transaction(function () use ($validated, $type, $isPurchase, &$reusedParty) {
            $partyId = null;

            if ($isPurchase) {
                [$partyId, $reusedParty] = $this->resolveSupplier($validated);
            }

            return DailyBookEntry::create([
                'type' => $type,
                'site_id' => $validated['site_id'] ?? Auth::user()->current_site_id,
                'party_id' => $partyId,
                'entry_date' => $validated['entry_date'],
                'amount' => $validated['amount'],
                'paid_amount' => $isPurchase ? $validated['paid_amount'] : null,
                'note' => $validated['note'] ?? null,
                'created_by' => Auth::id(),
            ]);
        });

        if ($request->hasFile('photo')) {
            $file = $request->file('photo');

            $entry->attachments()->create([
                'label' => $file->getClientOriginalName(),
                'path' => $file->store('daily-book', 'public'),
                'original_name' => $file->getClientOriginalName(),
                'uploaded_by' => Auth::id(),
            ]);
        }

        $message = __(':type entry saved.', ['type' => ucfirst($type)]);

        if ($reusedParty) {
            $message .= ' '.__('That phone number already belonged to :name, so the purchase was recorded under them.', ['name' => $reusedParty->name]);
        }

        return redirect()->route('daily-book.entries.index', $type)->with('success', $message);
    }

    /**
     * Picks the supplier for a purchase: a quick-added one (name + phone)
     * wins over the dropdown. Phone is unique on parties, so a quick add
     * with a number that's already on file reuses that party (marking them
     * a supplier if they were only a customer) instead of failing — and
     * returns them so the caller can tell the owner which name it went to.
     *
     * @return array{0: ?int, 1: ?Party} [party id, the existing party reused by phone (if any)]
     */
    protected function resolveSupplier(array $validated): array
    {
        if (empty($validated['new_party_name'])) {
            return [$validated['party_id'] ?? null, null];
        }

        $phone = trim($validated['new_party_phone']);
        $existing = Party::where('phone', $phone)->first();

        if ($existing) {
            if (! $existing->is_supplier) {
                $existing->update(['is_supplier' => true]);
            }

            return [$existing->id, $existing];
        }

        // No opening balance, so Party's created() hook posts nothing to
        // the ledger — the supplier's dues stay Daily-Book-only.
        $party = Party::create([
            'name' => trim($validated['new_party_name']),
            'phone' => $phone,
            'is_supplier' => true,
            'status' => true,
        ]);

        return [$party->id, null];
    }

    /**
     * Every supplier with their outstanding Daily Book due, highest first,
     * with Quick Pay. Inactive suppliers still show while they're owed
     * money, so a due can never disappear from view.
     */
    public function supplierIndex()
    {
        $dues = DailyBookEntry::supplierDues();

        $suppliers = Party::where('is_supplier', true)
            ->where(fn ($q) => $q->where('status', true)->orWhereIn('id', $dues->keys()))
            ->orderBy('name')
            ->get(['id', 'name', 'phone'])
            ->each(fn (Party $party) => $party->setAttribute('daily_book_due', $dues[$party->id] ?? 0.0))
            ->sortByDesc('daily_book_due')
            ->values();

        return view('admin.daily-book.supplier-index', [
            'suppliers' => $suppliers,
            'totalDue' => $suppliers->sum('daily_book_due'),
        ]);
    }

    /**
     * Add Supplier from the supplier list — same name + phone as the
     * purchase form's quick add. Phone is unique on parties: a number
     * that's already a customer just gets the supplier flag too (one
     * person, two roles — the parties table's own convention); one that's
     * already a supplier is refused, since they're already on the list.
     */
    public function supplierStore(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
        ]);

        $phone = trim($validated['phone']);
        $existing = Party::where('phone', $phone)->first();

        if ($existing?->is_supplier) {
            throw ValidationException::withMessages([
                'phone' => __(':name is already on your supplier list with this phone number.', ['name' => $existing->name]),
            ]);
        }

        if ($existing) {
            $existing->update(['is_supplier' => true, 'status' => true]);
            $message = __(':name was already saved with this phone number — added to your suppliers.', ['name' => $existing->name]);
        } else {
            // No opening balance, so Party's created() hook posts nothing to
            // the ledger.
            $party = Party::create([
                'name' => trim($validated['name']),
                'phone' => $phone,
                'is_supplier' => true,
                'status' => true,
            ]);
            $message = __('Supplier :name added.', ['name' => $party->name]);
        }

        return redirect()->route('daily-book.suppliers.index')->with('success', $message);
    }

    /**
     * One supplier's Daily Book khata, loaded into the modal on the supplier
     * list (returns a bare partial, no layout). Oldest first with a running
     * due: a purchase adds whatever wasn't paid on the spot, a payment takes
     * it off — so the last balance equals the due shown on the list
     * (DailyBookEntry::supplierDues()).
     */
    public function supplierLedger(Party $party)
    {
        abort_unless($party->is_supplier, 404);

        $entries = DailyBookEntry::with('attachments')
            ->where('party_id', $party->id)
            ->whereIn('type', ['purchase', DailyBookEntry::SUPPLIER_PAYMENT])
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();

        $balance = 0.0;

        $rows = $entries->map(function (DailyBookEntry $entry) use (&$balance) {
            $isPurchase = $entry->type === 'purchase';
            $purchased = $isPurchase ? (float) $entry->amount : 0.0;
            $paid = $isPurchase ? (float) $entry->paid_amount : (float) $entry->amount;
            $balance += $purchased - $paid;

            return (object) [
                'entry' => $entry,
                'is_purchase' => $isPurchase,
                'purchased' => $purchased,
                'paid' => $paid,
                'balance' => round($balance, 2),
            ];
        });

        return view('admin.daily-book.supplier-ledger', [
            'party' => $party,
            'rows' => $rows,
            'totalPurchased' => $rows->sum('purchased'),
            'totalPaid' => $rows->sum('paid'),
            'due' => round($balance, 2),
        ]);
    }

    public function supplierPay(Request $request, Party $party)
    {
        abort_unless($party->is_supplier, 404);

        $due = DailyBookEntry::supplierDues()[$party->id] ?? 0.0;

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:'.$due],
            'entry_date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [
            'amount.max' => __('You only owe :name :due.', ['name' => $party->name, 'due' => number_format($due, 2)]),
        ]);

        DailyBookEntry::create([
            'type' => DailyBookEntry::SUPPLIER_PAYMENT,
            'site_id' => Auth::user()->current_site_id,
            'party_id' => $party->id,
            'entry_date' => $validated['entry_date'],
            'amount' => $validated['amount'],
            'note' => $validated['note'] ?? null,
            'created_by' => Auth::id(),
        ]);

        return redirect()->route('daily-book.suppliers.index')
            ->with('success', __('Paid :amount to :name.', ['amount' => number_format((float) $validated['amount'], 2), 'name' => $party->name]));
    }

    protected function assertValidType(string $type): void
    {
        if (! in_array($type, DailyBookEntry::TYPES, true)) {
            throw new NotFoundHttpException();
        }
    }
}
