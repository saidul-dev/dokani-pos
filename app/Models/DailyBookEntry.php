<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 * entry effectively is one). Cash in Hand on the Summary page is
 * Total Capital + Total Sale − Total Purchase − Total Expense.
 *
 * Optionally carries one photo via HasAttachments (e.g. a phone-camera
 * shot of a receipt/bill) — entirely optional, not required to save an
 * entry.
 */
class DailyBookEntry extends Model
{
    use HasAttachments;

    public const TYPES = ['purchase', 'sale', 'expense', 'capital'];

    protected $fillable = [
        'type',
        'site_id',
        'entry_date',
        'amount',
        'note',
        'created_by',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
