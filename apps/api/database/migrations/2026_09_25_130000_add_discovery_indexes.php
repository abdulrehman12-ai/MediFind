<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE INDEX medicines_active_generic_name_index ON medicines (normalized_generic_name) WHERE status = 'active'");
        DB::statement("CREATE INDEX medicines_active_brand_name_index ON medicines (normalized_brand_name) WHERE status = 'active' AND normalized_brand_name <> ''");
        DB::statement(<<<'SQL'
CREATE INDEX pharmacy_inventory_pickup_discovery_index
ON pharmacy_inventory (medicine_id, pharmacy_id, last_verified_at)
WHERE is_available_for_pickup IS TRUE
  AND stock_status IN ('in_stock', 'low_stock')
SQL);
        DB::statement(<<<'SQL'
CREATE INDEX pharmacies_eligible_location_gist
ON pharmacies USING GIST (location)
WHERE status = 'approved'
  AND license_status = 'verified'
  AND location_review_status = 'approved'
  AND location IS NOT NULL
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS pharmacies_eligible_location_gist');
        DB::statement('DROP INDEX IF EXISTS pharmacy_inventory_pickup_discovery_index');
        DB::statement('DROP INDEX IF EXISTS medicines_active_brand_name_index');
        DB::statement('DROP INDEX IF EXISTS medicines_active_generic_name_index');
    }
};
