<?php

namespace App\Models;

use App\Services\LedgerService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Party extends Model
{
    public const OPENING_BALANCE_TYPES = ['due', 'advance'];

    /**
     * The one shared "Walk-in Customer" — who a sale is recorded against when
     * no customer is picked (POS checkout, Daily Book Sale Entry). Identified
     * by this placeholder phone since phone is the unique key on parties.
     */
    public const WALKIN_PHONE = '0000000000';

    protected $fillable = [
        'is_customer',
        'is_supplier',
        'is_company',
        'name',
        'contact_person',
        'designation',
        'phone',
        'email',
        'address',
        'nid_no',
        'bin_no',
        'tin_no',
        'credit_limit',
        'credit_days',
        'opening_balance',
        'opening_balance_type',
        'status',
        'notes',
    ];

    protected $casts = [
        'is_customer' => 'boolean',
        'is_supplier' => 'boolean',
        'is_company' => 'boolean',
        'credit_limit' => 'decimal:2',
        'credit_days' => 'integer',
        'opening_balance' => 'decimal:2',
        'status' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::created(function (Party $party) {
            $party->postOpeningBalanceToLedger();
        });
    }

    public static function walkIn(): self
    {
        return static::firstOrCreate(
            ['phone' => self::WALKIN_PHONE],
            ['name' => 'Walk-in Customer', 'is_customer' => true, 'opening_balance_type' => 'due', 'status' => true]
        );
    }

    public function isWalkIn(): bool
    {
        return $this->phone === self::WALKIN_PHONE;
    }

    /**
     * Name to show on screen. The walk-in is a system record, so its name is
     * translated rather than shown as stored; everyone else's name is data.
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->isWalkIn() ? __('Walk-in Customer') : $this->name;
    }

    public function getRoleLabelAttribute(): string
    {
        return match (true) {
            $this->is_customer && $this->is_supplier => 'Customer & Supplier',
            $this->is_supplier => 'Supplier',
            default => 'Customer',
        };
    }

    public function lines(): HasMany
    {
        return $this->hasMany(LedgerTransactionLine::class);
    }

    /**
     * Positive = this party owes the business (Accounts Receivable balance).
     */
    public function receivableBalance(): float
    {
        return (float) ($this->lines()
            ->whereHas('account', fn ($q) => $q->where('code', 'accounts_receivable'))
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as bal')
            ->value('bal') ?? 0);
    }

    /**
     * Positive = the business owes this party (Accounts Payable balance).
     */
    public function payableBalance(): float
    {
        return (float) ($this->lines()
            ->whereHas('account', fn ($q) => $q->where('code', 'accounts_payable'))
            ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) as bal')
            ->value('bal') ?? 0);
    }

    /**
     * One-time entry that gets this party's ledger balance in sync with the
     * `opening_balance`/`opening_balance_type` fields captured on the form.
     * Only ever runs once, right after creation (see booted()) — editing
     * opening_balance later does NOT currently adjust the ledger; that
     * needs a reversing-entry mechanism, deferred until it's actually
     * needed. `App\Console\Commands\BackfillPartyOpeningBalances` covers
     * parties created before this existed.
     */
    public function postOpeningBalanceToLedger(): void
    {
        $amount = round((float) $this->opening_balance, 2);

        if ($amount <= 0) {
            return;
        }

        // A party that's only a Supplier posts against Accounts Payable;
        // everyone else (Customer, or Customer+Supplier) posts against
        // Accounts Receivable — same default-to-Customer precedent as the
        // party form (is_customer defaults true).
        $usePayable = $this->is_supplier && ! $this->is_customer;
        $controlCode = $usePayable ? 'accounts_payable' : 'accounts_receivable';
        $isDue = $this->opening_balance_type === 'due';

        // Customer + due = customer owes us -> Debit Accounts Receivable.
        // Supplier + due = we owe the supplier -> Credit Accounts Payable.
        // "advance" flips the same account the other way.
        $controlIsDebit = $usePayable ? ! $isDue : $isDue;

        LedgerService::post([
            'type' => 'opening_balance',
            'narration' => "Opening balance for {$this->name}",
            'reference' => $this,
            'lines' => [
                [
                    'account' => $controlCode,
                    'party_id' => $this->id,
                    'debit' => $controlIsDebit ? $amount : 0,
                    'credit' => $controlIsDebit ? 0 : $amount,
                ],
                [
                    'account' => 'opening_balance_equity',
                    'debit' => $controlIsDebit ? 0 : $amount,
                    'credit' => $controlIsDebit ? $amount : 0,
                ],
            ],
        ]);
    }
}
