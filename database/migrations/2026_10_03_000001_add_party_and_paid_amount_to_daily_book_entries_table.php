<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a Daily Book purchase be bought on credit from a supplier (
 * wholesaler) — reusing the existing `parties` table rather than a separate
 * supplier list, so the same supplier shows up in the full system's Parties
 * module once the shop upgrades.
 *
 * Dues stay entirely inside Daily Book: due = amount − paid_amount on
 * 'purchase' entries, settled by 'supplier_payment' entries. Nothing here
 * posts to LedgerTransaction, so a party's Accounts Payable balance in the
 * full system does not reflect Daily Book dues — same "Daily Book never
 * touches Accounts" rule as the rest of the module.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_book_entries', function (Blueprint $table) {
            // Restrict, not null-on-delete: a supplier with an outstanding due
            // shouldn't be able to silently vanish and take the due with it.
            $table->foreignId('party_id')->nullable()->after('site_id')->constrained('parties')->restrictOnDelete();

            // Purchases only — how much was paid at the time of purchase.
            $table->decimal('paid_amount', 14, 2)->nullable()->after('amount');

            $table->index(['party_id', 'type']);
        });

        // Every purchase logged before this existed was a cash purchase.
        DB::table('daily_book_entries')->where('type', 'purchase')->update(['paid_amount' => DB::raw('amount')]);
    }

    public function down(): void
    {
        Schema::table('daily_book_entries', function (Blueprint $table) {
            $table->dropIndex(['party_id', 'type']);
            $table->dropConstrainedForeignId('party_id');
            $table->dropColumn('paid_amount');
        });
    }
};
