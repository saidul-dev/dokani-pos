<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Daily Book sales logged before customers existed have no party_id. Under
 * the current rule "no customer picked = Walk-in Customer", they're walk-in
 * sales — so attach them to the walk-in, letting its ledger on the
 * Customers page show the shop's whole walk-in history, not just sales made
 * after the customer feature shipped.
 *
 * Only fully-received ones: the walk-in can never carry a due, and a sale
 * with no customer couldn't have kept one anyway (2026_10_05_000001
 * backfilled paid_amount = amount on these).
 */
return new class extends Migration
{
    public function up(): void
    {
        $unattributed = DB::table('daily_book_entries')
            ->where('type', 'sale')
            ->whereNull('party_id')
            ->whereRaw('COALESCE(paid_amount, amount) >= amount');

        if (! $unattributed->exists()) {
            return;
        }

        // Same record Party::walkIn() resolves (Party::WALKIN_PHONE).
        $walkInId = DB::table('parties')->where('phone', '0000000000')->value('id')
            ?? DB::table('parties')->insertGetId([
                'name' => 'Walk-in Customer',
                'phone' => '0000000000',
                'is_customer' => true,
                'is_supplier' => false,
                'is_company' => false,
                'opening_balance' => 0,
                'opening_balance_type' => 'due',
                'status' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $unattributed->update(['party_id' => $walkInId]);
    }

    public function down(): void
    {
        // Left in place: nothing distinguishes these rows from walk-in sales
        // logged afterwards, and they're correct either way.
    }
};
