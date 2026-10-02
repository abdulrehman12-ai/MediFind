<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE orders ADD CONSTRAINT orders_collected_cash_present_check CHECK (
    status <> 'collected'
    OR cash_collected_paisa IS NOT NULL
)
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_collected_cash_present_check');
    }
};
