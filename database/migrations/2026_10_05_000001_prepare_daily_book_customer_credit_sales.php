<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Daily Book sales can now be made on credit to a customer — mirror of the
 * supplier side (2026_10_03_000001): `paid_amount` on a 'sale' is what was
 * received at the time, due = amount − paid_amount, settled later by
 * 'customer_collection' entries. No schema change needed — party_id and
 * paid_amount already exist on daily_book_entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Every sale logged before this existed was received in full.
        DB::table('daily_book_entries')
            ->where('type', 'sale')
            ->whereNull('paid_amount')
            ->update(['paid_amount' => DB::raw('amount')]);

        // PartySeeder used to create "Walk-in Customer" under 01700000001,
        // while POS created its own under 0000000000 (now Party::WALKIN_PHONE)
        // — two walk-ins. If only the seeded one exists, give it the shared
        // phone so it becomes the one walk-in; if both exist, leave them be
        // rather than guess which history to keep.
        $hasShared = DB::table('parties')->where('phone', '0000000000')->exists();

        if (! $hasShared) {
            DB::table('parties')
                ->where('phone', '01700000001')
                ->where('name', 'Walk-in Customer')
                ->update(['phone' => '0000000000']);
        }
    }

    public function down(): void
    {
        // Data-only and safe to leave in place: fully-received sales stay
        // fully received, and the walk-in keeps working under either phone.
    }
};
