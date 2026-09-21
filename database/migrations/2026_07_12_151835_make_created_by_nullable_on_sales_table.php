<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A guest online order has no authenticated user to attribute it to — the
 * FK stays (guest.sale.creator will just be null, and the Sale show page
 * already falls back to '—' for that). Raw SQL because the project doesn't
 * have doctrine/dbal installed, which Schema::table()->change() requires.
 * MySQL's MODIFY syntax isn't understood by SQLite (used locally/in tests),
 * so branch on driver — SQLite already allows NULL unless the column was
 * declared NOT NULL, and recreates the table under the hood for ALTER, so
 * a no-op here is fine for that driver.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE sales MODIFY created_by BIGINT UNSIGNED NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE sales MODIFY created_by BIGINT UNSIGNED NOT NULL');
        }
    }
};
