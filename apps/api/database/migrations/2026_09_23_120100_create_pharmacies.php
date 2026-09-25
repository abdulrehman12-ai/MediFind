<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('city_id')->constrained('cities')->restrictOnDelete();
            $table->string('name', 180);
            $table->string('phone_e164', 20)->nullable();
            $table->string('address_text', 300);
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->string('place_id')->nullable();
            $table->text('pickup_instructions')->nullable();
            $table->string('status', 32)->default('pending');
            $table->text('status_reason')->nullable();
            $table->foreignId('status_reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('status_reviewed_at')->nullable();
            $table->text('appeal_note')->nullable();
            $table->timestampTz('appeal_submitted_at')->nullable();
            $table->string('license_number', 100)->nullable();
            $table->string('license_status', 32)->default('unverified');
            $table->foreignId('license_reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('license_reviewed_at')->nullable();
            $table->string('license_verification_method', 64)->nullable();
            $table->text('license_evidence')->nullable();
            $table->string('location_review_status', 32)->default('pending');
            $table->foreignId('location_reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('location_reviewed_at')->nullable();
            $table->string('location_verification_method', 64)->nullable();
            $table->text('location_evidence')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE pharmacies ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(<<<'SQL'
ALTER TABLE pharmacies
ADD COLUMN location geography(Point, 4326)
GENERATED ALWAYS AS (
    CASE
        WHEN latitude IS NULL OR longitude IS NULL THEN NULL
        ELSE ST_SetSRID(ST_MakePoint(longitude, latitude), 4326)::geography
    END
) STORED
SQL);
        DB::statement('CREATE INDEX pharmacies_location_gist ON pharmacies USING GIST (location)');
        DB::statement("ALTER TABLE pharmacies ADD CONSTRAINT pharmacies_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'suspended'))");
        DB::statement("ALTER TABLE pharmacies ADD CONSTRAINT pharmacies_license_status_check CHECK (license_status IN ('unverified', 'verified', 'rejected', 'expired'))");
        DB::statement("ALTER TABLE pharmacies ADD CONSTRAINT pharmacies_location_review_status_check CHECK (location_review_status IN ('pending', 'approved', 'rejected'))");
        DB::statement(<<<'SQL'
ALTER TABLE pharmacies ADD CONSTRAINT pharmacies_license_verification_check CHECK ((
    license_status IS DISTINCT FROM 'verified'
    OR (
        license_reviewed_by IS NOT NULL
        AND license_reviewed_at IS NOT NULL
        AND license_verification_method = 'documented_admin_review'
        AND license_evidence IS NOT NULL
        AND btrim(license_evidence) <> ''
        AND license_number IS NOT NULL
        AND btrim(license_number) <> ''
    )
) IS TRUE)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE pharmacies ADD CONSTRAINT pharmacies_location_verification_check CHECK ((
    location_review_status IS DISTINCT FROM 'approved'
    OR (
        location_reviewed_by IS NOT NULL
        AND location_reviewed_at IS NOT NULL
        AND location_verification_method = 'documented_admin_review'
        AND location_evidence IS NOT NULL
        AND btrim(location_evidence) <> ''
        AND latitude IS NOT NULL
        AND longitude IS NOT NULL
    )
) IS TRUE)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE pharmacies ADD CONSTRAINT pharmacies_latitude_check CHECK (
    latitude IS NULL
    OR (
        latitude::text NOT IN ('NaN', 'Infinity', '-Infinity')
        AND latitude >= -90
        AND latitude <= 90
    )
)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE pharmacies ADD CONSTRAINT pharmacies_longitude_check CHECK (
    longitude IS NULL
    OR (
        longitude::text NOT IN ('NaN', 'Infinity', '-Infinity')
        AND longitude >= -180
        AND longitude <= 180
    )
)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE pharmacies ADD CONSTRAINT pharmacies_decision_reason_check CHECK ((
    status IS DISTINCT FROM 'rejected' AND status IS DISTINCT FROM 'suspended'
    OR (
        status_reason IS NOT NULL
        AND btrim(status_reason) <> ''
    )
) IS TRUE)
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.medifind_invalidate_pharmacy_review()
RETURNS trigger
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF NEW.license_number IS DISTINCT FROM OLD.license_number THEN
        NEW.license_status := 'unverified';
        NEW.license_reviewed_by := NULL;
        NEW.license_reviewed_at := NULL;
        NEW.license_verification_method := NULL;
        NEW.license_evidence := NULL;
    END IF;

    IF NEW.latitude IS DISTINCT FROM OLD.latitude OR NEW.longitude IS DISTINCT FROM OLD.longitude THEN
        NEW.location_review_status := 'pending';
        NEW.location_reviewed_by := NULL;
        NEW.location_reviewed_at := NULL;
        NEW.location_verification_method := NULL;
        NEW.location_evidence := NULL;
    END IF;

    RETURN NEW;
END;
$fn$;

CREATE TRIGGER medifind_invalidate_pharmacy_review
BEFORE UPDATE ON pharmacies
FOR EACH ROW
EXECUTE FUNCTION public.medifind_invalidate_pharmacy_review();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS medifind_invalidate_pharmacy_review ON pharmacies;
DROP FUNCTION IF EXISTS public.medifind_invalidate_pharmacy_review();
SQL);
        Schema::dropIfExists('pharmacies');
    }
};
