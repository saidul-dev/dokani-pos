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
use Illuminate\Support\Collection;
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
                ? $from->translatedFormat('d M Y')
                : $from->translatedFormat('d M Y').' – '.$to->translatedFormat('d M Y');
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
     * Cash actually in the till, across ALL TIME — deliberately not scoped to
     * the Summary page's selected range, since "cash in hand" only makes
     * sense as a running balance from the first entry ever logged. Counts
     * only money that changed hands: a credit sale adds just what was
     * received on the spot (the rest comes in later as customer
     * collections), a credit purchase takes out just what was paid (the rest
     * leaves later as supplier payments). 'capital' entries are the owner
     * putting money in (see DailyBookEntry) — the first one effectively is
     * the starting balance.
     */
    protected function cashInHand(?int $siteId): float
    {
        $allTime = fn (string $type, string $column = 'amount') => (float) DailyBookEntry::where('type', $type)
            ->when($siteId && $type !== 'expense', fn ($q) => $q->where('site_id', $siteId))
            ->sum($column);

        return $allTime('capital')
            + $allTime('sale', 'paid_amount')
            + $allTime(DailyBookEntry::CUSTOMER_COLLECTION)
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
     * 'expense' | 'capital') — see DailyBookEntry. Quick log only: a plain
     * amount + date + note, no product lines, no ledger posting, no stock
     * effect. Purchase and sale also carry a party and what was paid /
     * received on the spot (DailyBookEntry::SIDES).
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
            'side' => DailyBookEntry::sideFor($type),
            'entries' => $entries,
        ]);
    }

    public function entryCreate(string $type)
    {
        $this->assertValidType($type);

        $side = DailyBookEntry::sideFor($type);

        return view('admin.daily-book.entry-create', [
            'type' => $type,
            'side' => $side,
            'sites' => Site::where('status', true)->orderBy('name')->get(),
            'parties' => $side ? $this->partyOptions($side) : collect(),
        ]);
    }

    public function entryStore(Request $request, string $type)
    {
        $this->assertValidType($type);

        $side = DailyBookEntry::sideFor($type);
        $isCustomer = $side === 'customer';

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

        if ($side) {
            $rules += [
                // What was paid (purchase) / received (sale) on the spot —
                // typed by the owner (or "Full amount" on the form), never
                // assumed, so a forgotten field can't silently record a credit
                // deal as settled. Anything less than amount is a due.
                'paid_amount' => ['required', 'numeric', 'min:0', 'lte:amount'],
                'party_id' => ['nullable', Rule::exists('parties', 'id')->where(DailyBookEntry::SIDES[$side]['flag'], true)],
                // Quick add — just name + phone, the same two things a shop
                // owner writes in their khata for someone new.
                'new_party_name' => ['nullable', 'required_with:new_party_phone', 'string', 'max:255'],
                'new_party_phone' => ['nullable', 'required_with:new_party_name', 'string', 'max:30'],
            ];
        }

        $validated = $request->validate($rules, [
            'paid_amount.required' => $isCustomer
                ? __('Enter how much you received now — 0 if you received nothing.')
                : __('Enter how much you paid now — 0 if you paid nothing.'),
            'paid_amount.lte' => $isCustomer
                ? __('Received amount can\'t be more than the sale amount.')
                : __('Paid amount can\'t be more than the purchase amount.'),
        ]);

        $keepsDue = $side && (float) $validated['paid_amount'] < (float) $validated['amount'];
        $dueNeedsPartyMessage = $isCustomer
            ? __('To keep a due, choose or add the customer who owes it — not the Walk-in Customer.')
            : __('To keep a due, choose or add the supplier you owe.');

        if ($keepsDue && empty($validated['party_id']) && empty($validated['new_party_name'])) {
            throw ValidationException::withMessages(['party_id' => $dueNeedsPartyMessage]);
        }

        $reusedParty = null;

        $entry = DB::transaction(function () use ($validated, $type, $side, $isCustomer, $keepsDue, $dueNeedsPartyMessage, &$reusedParty) {
            $partyId = null;

            if ($side) {
                [$partyId, $reusedParty] = $this->resolveParty($validated, $side);

                // No customer picked on a sale → it was a walk-in sale.
                if ($isCustomer && $partyId === null) {
                    $partyId = Party::walkIn()->id;
                }

                // Nobody can owe a due as "Walk-in" — they'd never be found
                // again to collect it. (Thrown inside the transaction, so a
                // party created by the quick add above is rolled back too.)
                if ($keepsDue && $partyId === Party::walkIn()->id) {
                    throw ValidationException::withMessages(['party_id' => $dueNeedsPartyMessage]);
                }
            }

            return DailyBookEntry::create([
                'type' => $type,
                'site_id' => $validated['site_id'] ?? Auth::user()->current_site_id,
                'party_id' => $partyId,
                'entry_date' => $validated['entry_date'],
                'amount' => $validated['amount'],
                'paid_amount' => $side ? $validated['paid_amount'] : null,
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

        $message = __(':type entry saved.', ['type' => __(ucfirst($type))]);

        if ($reusedParty) {
            $message .= ' '.__('That phone number already belonged to :name, so the :type was recorded under them.', ['name' => $reusedParty->name, 'type' => __($type)]);
        }

        return redirect()->route('daily-book.entries.index', $type)->with('success', $message);
    }

    /**
     * The picker options for a side: active parties with that side's flag.
     * The walk-in is left out — on a sale it's what "no customer" means, and
     * it's never a real supplier.
     */
    protected function partyOptions(string $side): Collection
    {
        return Party::where(DailyBookEntry::SIDES[$side]['flag'], true)
            ->where('status', true)
            ->where('phone', '!=', Party::WALKIN_PHONE)
            ->orderBy('name')
            ->get(['id', 'name', 'phone']);
    }

    /**
     * Picks the party for a purchase/sale: a quick-added one (name + phone)
     * wins over the dropdown. Phone is unique on parties, so a quick add with
     * a number that's already on file reuses that party (giving them this
     * side's flag if they only had the other) instead of failing — and
     * returns them so the caller can tell the owner which name it went to.
     *
     * @return array{0: ?int, 1: ?Party} [party id, the existing party reused by phone (if any)]
     */
    protected function resolveParty(array $validated, string $side): array
    {
        if (empty($validated['new_party_name'])) {
            // Cast: the request gives "1", and callers compare ids strictly
            // (e.g. the walk-in check in entryStore).
            return [isset($validated['party_id']) ? (int) $validated['party_id'] : null, null];
        }

        $flag = DailyBookEntry::SIDES[$side]['flag'];
        $phone = trim($validated['new_party_phone']);
        $existing = Party::where('phone', $phone)->first();

        if ($existing) {
            if (! $existing->{$flag}) {
                $existing->update([$flag => true]);
            }

            return [$existing->id, $existing];
        }

        // No opening balance, so Party's created() hook posts nothing to the
        // ledger — the party's dues stay Daily-Book-only.
        $party = Party::create([
            'name' => trim($validated['new_party_name']),
            'phone' => $phone,
            $flag => true,
            'status' => true,
        ]);

        return [$party->id, null];
    }

    // ── Suppliers & Customers ───────────────────────────────────────────
    // Same screens and rules on both sides (DailyBookEntry::SIDES), so each
    // public action below is a thin wrapper naming its side.

    public function supplierIndex()
    {
        return $this->partyIndex('supplier');
    }

    public function customerIndex()
    {
        return $this->partyIndex('customer');
    }

    public function supplierStore(Request $request)
    {
        return $this->partyStore($request, 'supplier');
    }

    public function customerStore(Request $request)
    {
        return $this->partyStore($request, 'customer');
    }

    public function supplierLedger(Party $party)
    {
        return $this->partyLedger($party, 'supplier');
    }

    public function customerLedger(Party $party)
    {
        return $this->partyLedger($party, 'customer');
    }

    public function supplierPay(Request $request, Party $party)
    {
        return $this->partySettle($request, $party, 'supplier');
    }

    public function customerCollect(Request $request, Party $party)
    {
        return $this->partySettle($request, $party, 'customer');
    }

    /**
     * Everyone on one side with their outstanding Daily Book due, highest
     * first, with Quick Pay / Quick Collect. Inactive parties still show
     * while a due is open, so a due can never disappear from view. The
     * walk-in is listed (its ledger is the shop's walk-in sales) but always
     * last — it can never carry a due, so it never needs collecting from.
     */
    protected function partyIndex(string $side)
    {
        $dues = DailyBookEntry::dues($side);

        $parties = Party::where(DailyBookEntry::SIDES[$side]['flag'], true)
            ->where(fn ($q) => $q->where('status', true)->orWhereIn('id', $dues->keys()))
            ->orderBy('name')
            ->get(['id', 'name', 'phone'])
            ->each(fn (Party $party) => $party->setAttribute('daily_book_due', $dues[$party->id] ?? 0.0))
            ->sortBy([
                fn (Party $a, Party $b) => $a->isWalkIn() <=> $b->isWalkIn(),
                fn (Party $a, Party $b) => $b->daily_book_due <=> $a->daily_book_due,
            ])
            ->values();

        return view('admin.daily-book.party-index', [
            'side' => $side,
            'parties' => $parties,
            'totalDue' => $parties->sum('daily_book_due'),
        ]);
    }

    /**
     * Add Supplier / Add Customer — same name + phone as the entry form's
     * quick add. Phone is unique on parties: a number already on file under
     * the other side just gets this side's flag too (one person, two roles —
     * the parties table's own convention); one already on this side is
     * refused, since they're already on the list.
     */
    protected function partyStore(Request $request, string $side)
    {
        $flag = DailyBookEntry::SIDES[$side]['flag'];

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
        ]);

        $phone = trim($validated['phone']);

        if ($phone === Party::WALKIN_PHONE) {
            throw ValidationException::withMessages(['phone' => __('That phone number is reserved for the Walk-in Customer.')]);
        }

        $existing = Party::where('phone', $phone)->first();

        if ($existing?->{$flag}) {
            throw ValidationException::withMessages([
                'phone' => $side === 'customer'
                    ? __(':name is already on your customer list with this phone number.', ['name' => $existing->name])
                    : __(':name is already on your supplier list with this phone number.', ['name' => $existing->name]),
            ]);
        }

        if ($existing) {
            $existing->update([$flag => true, 'status' => true]);
            $message = $side === 'customer'
                ? __(':name was already saved with this phone number — added to your customers.', ['name' => $existing->name])
                : __(':name was already saved with this phone number — added to your suppliers.', ['name' => $existing->name]);
        } else {
            // No opening balance, so Party's created() hook posts nothing to
            // the ledger.
            $party = Party::create([
                'name' => trim($validated['name']),
                'phone' => $phone,
                $flag => true,
                'status' => true,
            ]);
            $message = $side === 'customer'
                ? __('Customer :name added.', ['name' => $party->name])
                : __('Supplier :name added.', ['name' => $party->name]);
        }

        return redirect()->route($this->sideRoute($side, 'index'))->with('success', $message);
    }

    /**
     * One party's Daily Book khata, loaded into the modal on the list
     * (returns a bare partial, no layout). Oldest first with a running due:
     * a purchase/sale adds whatever wasn't settled on the spot, a payment /
     * collection takes it off — so the last balance equals the due shown on
     * the list (DailyBookEntry::dues()).
     */
    protected function partyLedger(Party $party, string $side)
    {
        // The walk-in's ledger is allowed (it's the shop's walk-in sales);
        // only settling against it is blocked, in partySettle().
        abort_unless($party->{DailyBookEntry::SIDES[$side]['flag']}, 404);

        ['entry' => $entryType, 'settle' => $settleType] = DailyBookEntry::SIDES[$side];

        $entries = DailyBookEntry::with('attachments')
            ->where('party_id', $party->id)
            ->whereIn('type', [$entryType, $settleType])
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();

        $balance = 0.0;

        $rows = $entries->map(function (DailyBookEntry $entry) use ($entryType, &$balance) {
            $isEntry = $entry->type === $entryType;
            $billed = $isEntry ? (float) $entry->amount : 0.0;
            $settled = $isEntry ? (float) $entry->paid_amount : (float) $entry->amount;
            $balance += $billed - $settled;

            return (object) [
                'entry' => $entry,
                'is_entry' => $isEntry,
                'billed' => $billed,
                'settled' => $settled,
                'balance' => round($balance, 2),
            ];
        });

        return view('admin.daily-book.party-ledger', [
            'side' => $side,
            'party' => $party,
            'rows' => $rows,
            'totalBilled' => $rows->sum('billed'),
            'totalSettled' => $rows->sum('settled'),
            'due' => round($balance, 2),
        ]);
    }

    /** Quick Pay (supplier) / Quick Collect (customer) — can't exceed the open due. */
    protected function partySettle(Request $request, Party $party, string $side)
    {
        $this->assertCanSettle($party, $side);

        $due = DailyBookEntry::dues($side)[$party->id] ?? 0.0;
        $isCustomer = $side === 'customer';

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:'.$due],
            'entry_date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [
            'amount.max' => $isCustomer
                ? __(':name only owes you :due.', ['name' => $party->name, 'due' => number_format($due, 2)])
                : __('You only owe :name :due.', ['name' => $party->name, 'due' => number_format($due, 2)]),
        ]);

        DailyBookEntry::create([
            'type' => DailyBookEntry::SIDES[$side]['settle'],
            'site_id' => Auth::user()->current_site_id,
            'party_id' => $party->id,
            'entry_date' => $validated['entry_date'],
            'amount' => $validated['amount'],
            'note' => $validated['note'] ?? null,
            'created_by' => Auth::id(),
        ]);

        $amount = number_format((float) $validated['amount'], 2);

        return redirect()->route($this->sideRoute($side, 'index'))->with('success', $isCustomer
            ? __('Collected :amount from :name.', ['amount' => $amount, 'name' => $party->name])
            : __('Paid :amount to :name.', ['amount' => $amount, 'name' => $party->name]));
    }

    /** 'daily-book.suppliers.index', 'daily-book.customers.ledger', ... */
    protected function sideRoute(string $side, string $action): string
    {
        return 'daily-book.'.($side === 'customer' ? 'customers' : 'suppliers').'.'.$action;
    }

    /** On this side, and not the walk-in (which never has a due to settle). */
    protected function assertCanSettle(Party $party, string $side): void
    {
        abort_unless($party->{DailyBookEntry::SIDES[$side]['flag']} && ! $party->isWalkIn(), 404);
    }

    protected function assertValidType(string $type): void
    {
        if (! in_array($type, DailyBookEntry::TYPES, true)) {
            throw new NotFoundHttpException();
        }
    }
}
