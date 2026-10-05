<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * A single "Daily Book" quick log line — Purchase, Sale, Expense, or
 * Capital (money the owner puts into the business), logged as a plain
 * amount with no product lines and no ledger accounts. Deliberately
 * disconnected from Purchase/Sale/Expense/CapitalTransaction and from
 * LedgerTransaction/StockMovement — see the migration for why.
 *
 * 'capital' stands in for the real system's CapitalTransaction
 * 'investment' type (see app/Models/CapitalTransaction.php) — it's how
 * the owner records putting money into the business, repeatable at any
 * time (there's no separate "starting balance" concept; the first capital
 * entry effectively is one).
 *
 * Purchases and sales can both be on credit (see SIDES): a purchase from a
 * supplier, a sale to a customer. paid_amount is what changed hands at the
 * time, due = amount − paid_amount, settled later by 'supplier_payment' /
 * 'customer_collection' entries. These dues live only in Daily Book and are
 * never posted to the ledger, so Party::payableBalance() /
 * receivableBalance() won't see them.
 *
 * Optionally carries one photo via HasAttachments (e.g. a phone-camera
 * shot of a receipt/bill) — entirely optional, not required to save an
 * entry.
 */
class DailyBookEntry extends Model
{
    use HasAttachments;

    /**
     * Types with their own entry list/form (the {type} routes). Supplier
     * payments / customer collections are deliberately not among these —
     * they're only ever made through Quick Pay / Quick Collect on the
     * Suppliers / Customers list, which knows the due to settle.
     */
    public const TYPES = ['purchase', 'sale', 'expense', 'capital'];

    public const SUPPLIER_PAYMENT = 'supplier_payment';

    public const CUSTOMER_COLLECTION = 'customer_collection';

    /**
     * The two credit "sides" — identical mechanics, mirrored direction:
     * 'entry' is the type that can leave a due, 'settle' the type that pays
     * it down, 'flag' the parties column marking who belongs on that side.
     */
    public const SIDES = [
        'supplier' => ['entry' => 'purchase', 'settle' => self::SUPPLIER_PAYMENT, 'flag' => 'is_supplier'],
        'customer' => ['entry' => 'sale', 'settle' => self::CUSTOMER_COLLECTION, 'flag' => 'is_customer'],
    ];

    /** 'supplier' for 'purchase', 'customer' for 'sale', null for other types. */
    public static function sideFor(string $type): ?string
    {
        foreach (self::SIDES as $side => $config) {
            if ($config['entry'] === $type) {
                return $side;
            }
        }

        return null;
    }

    protected $fillable = [
        'type',
        'site_id',
        'party_id',
        'entry_date',
        'amount',
        'paid_amount',
        'note',
        'created_by',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getDueAmountAttribute(): float
    {
        return static::sideFor($this->type) !== null
            ? max(0, (float) $this->amount - (float) $this->paid_amount)
            : 0.0;
    }

    /**
     * Outstanding Daily Book due per party on one side, keyed by party_id:
     * Σ(entry amount − paid) − Σ(settle amounts). For 'supplier' that's what
     * the shop owes each supplier; for 'customer', what each customer owes
     * the shop. Company-wide, not per-site — a due is owed the same whichever
     * branch logged it.
     *
     * @return Collection<int, float>
     */
    public static function dues(string $side): Collection
    {
        ['entry' => $entry, 'settle' => $settle] = self::SIDES[$side];

        return static::query()
            ->whereNotNull('party_id')
            ->whereIn('type', [$entry, $settle])
            ->selectRaw('party_id, SUM(CASE WHEN type = ? THEN amount - COALESCE(paid_amount, amount) ELSE -amount END) as due', [$entry])
            ->groupBy('party_id')
            ->pluck('due', 'party_id')
            ->map(fn ($due) => round((float) $due, 2));
    }
}
