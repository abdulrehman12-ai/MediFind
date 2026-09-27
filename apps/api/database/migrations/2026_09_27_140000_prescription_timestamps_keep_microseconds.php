<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The exclusive lock, fractional-second check, and precision change must
     * commit or roll back together. PostgreSQL releases LOCK TABLE at the end
     * of the transaction.
     */
    public $withinTransaction = true;

    public function up(): void
    {
        DB::statement('ALTER TABLE prescriptions ALTER COLUMN created_at TYPE timestamp(6) without time zone');
        DB::statement('ALTER TABLE prescriptions ALTER COLUMN updated_at TYPE timestamp(6) without time zone');
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE prescriptions IN ACCESS EXCLUSIVE MODE');

        $fractional = (int) DB::scalar(<<<'SQL'
            select case when exists (
                select 1
                from prescriptions
                where created_at <> date_trunc('second', created_at)
                   or updated_at <> date_trunc('second', updated_at)
            ) then 1 else 0 end
        SQL);

        if ($fractional === 1) {
            throw new RuntimeException('Prescription timestamp precision cannot be rolled back while created_at or updated_at contains fractional seconds.');
        }

        DB::statement('ALTER TABLE prescriptions ALTER COLUMN updated_at TYPE timestamp(0) without time zone');
        DB::statement('ALTER TABLE prescriptions ALTER COLUMN created_at TYPE timestamp(0) without time zone');
    }
};
