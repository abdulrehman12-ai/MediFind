<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_addresses', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('city_id')->constrained('cities')->restrictOnDelete();
            $table->string('label', 60);
            $table->string('address_text', 300);
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            $table->string('place_id')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement('ALTER TABLE user_addresses ALTER COLUMN public_id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE user_addresses ALTER COLUMN latitude SET NOT NULL');
        DB::statement('ALTER TABLE user_addresses ALTER COLUMN longitude SET NOT NULL');
        DB::statement(<<<'SQL'
ALTER TABLE user_addresses
ADD COLUMN location geography(Point, 4326)
GENERATED ALWAYS AS (
    ST_SetSRID(ST_MakePoint(longitude, latitude), 4326)::geography
) STORED
SQL);
        DB::statement('CREATE INDEX user_addresses_location_gist ON user_addresses USING GIST (location)');
        DB::statement('CREATE UNIQUE INDEX user_addresses_one_default_unique ON user_addresses (user_id) WHERE is_default = true AND deleted_at IS NULL');
        DB::statement(<<<'SQL'
ALTER TABLE user_addresses
ADD CONSTRAINT user_addresses_latitude_check
CHECK (
    latitude IS NOT NULL
    AND latitude::text NOT IN ('NaN', 'Infinity', '-Infinity')
    AND latitude >= -90
    AND latitude <= 90
)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE user_addresses
ADD CONSTRAINT user_addresses_longitude_check
CHECK (
    longitude IS NOT NULL
    AND longitude::text NOT IN ('NaN', 'Infinity', '-Infinity')
    AND longitude >= -180
    AND longitude <= 180
)
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_addresses');
    }
};
