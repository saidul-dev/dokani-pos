<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Daily Book" quick entries (docs/future-ideas.md — "Daily Book" quick
 * daily Purchase/Sale/Expense entry). Deliberately standalone: this does
 * NOT post to LedgerTransaction, does NOT touch StockMovement, and is not
 * the same table as `purchases`/`sales`/`expenses`. It exists purely so a
 * shop owner can log "how much did I purchase/sell/spend today" as a
 * single number with no product lines, no ledger accounts, and no
 * approval flow — a separate, lightweight daily log that never feeds the
 * accounting or inventory system.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_book_entries', function (Blueprint $table) {
            $table->id();

            // 'purchase' | 'sale' | 'expense' — one shared table since all
            // three are structurally identical (date + amount + note).
            $table->string('type');

            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->date('entry_date');
            $table->decimal('amount', 14, 2);
            $table->text('note')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['type', 'entry_date']);
            $table->index(['site_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_book_entries');
    }
};
