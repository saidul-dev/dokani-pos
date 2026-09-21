<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily Book has no per-product cost/price, so it can never compute a real
 * profit the way the full Sale/Purchase system does — this is a single,
 * shop-wide *approximate* margin the owner sets themselves (e.g. "I
 * usually make about 15% on what I sell"), used only to estimate a rough
 * profit figure on the Daily Book Summary. Company-wide, so it lives on
 * CompanySetting rather than a new table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->decimal('daily_book_profit_margin_percent', 5, 2)->nullable()->after('pos_receipt_paper_width');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('daily_book_profit_margin_percent');
        });
    }
};
