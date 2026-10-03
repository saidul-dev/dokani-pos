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
 * A purchase can be bought on credit from a supplier (wholesaler —
 * a Party with is_supplier): due = amount − paid_amount, settled later by
 * 'supplier_payment' entries. These dues live only in Daily Book and are
 * never posted to the ledger, so Party::payableBalance() won't see them.
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
     * payments are deliberately not one of these — they're only ever made
     * through Quick Pay on the supplier list, which knows the due to settle.
     */
    public const TYPES = ['purchase', 'sale', 'expense', 'capital'];

    public const SUPPLIER_PAYMENT = 'supplier_payment';

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
        return $this->type === 'purchase'
            ? max(0, (float) $this->amount - (float) $this->paid_amount)
            : 0.0;
    }

    /**
     * Outstanding Daily Book due per supplier, keyed by party_id:
     * Σ(purchase amount − paid) − Σ(supplier payments). Company-wide, not
     * per-site — a supplier is owed the same money whichever branch bought.
     *
     * @return Collection<int, float>
     */
    public static function supplierDues(): Collection
    {
        return static::query()
            ->whereNotNull('party_id')
            ->whereIn('type', ['purchase', self::SUPPLIER_PAYMENT])
            ->selectRaw("party_id, SUM(CASE WHEN type = 'purchase' THEN amount - COALESCE(paid_amount, amount) ELSE -amount END) as due")
            ->groupBy('party_id')
            ->pluck('due', 'party_id')
            ->map(fn ($due) => round((float) $due, 2));
    }
}
