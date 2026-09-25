<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacy_inventory', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('pharmacy_id')->constrained('pharmacies')->restrictOnDelete();
            $table->foreignUuid('medicine_id')->constrained('medicines')->restrictOnDelete();
            $table->string('stock_status', 32);
            $table->integer('quantity_on_hand')->nullable();
            $table->unsignedBigInteger('unit_price_paisa');
            $table->boolean('is_available_for_pickup')->default(false);
            $table->timestampTz('last_verified_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['pharmacy_id', 'medicine_id']);
        });

        DB::statement('ALTER TABLE pharmacy_inventory ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE pharmacy_inventory ADD CONSTRAINT pharmacy_inventory_stock_status_check CHECK (stock_status IN ('unknown', 'in_stock', 'low_stock', 'out_of_stock'))");
        DB::statement('ALTER TABLE pharmacy_inventory ADD CONSTRAINT pharmacy_inventory_quantity_check CHECK (quantity_on_hand IS NULL OR quantity_on_hand >= 0)');
        DB::statement('ALTER TABLE pharmacy_inventory ADD CONSTRAINT pharmacy_inventory_version_check CHECK (version >= 1)');
        DB::statement('ALTER TABLE pharmacy_inventory ADD CONSTRAINT pharmacy_inventory_price_check CHECK (unit_price_paisa >= 0)');
        DB::statement(<<<'SQL'
ALTER TABLE pharmacy_inventory ADD CONSTRAINT pharmacy_inventory_state_check CHECK ((
    (
        stock_status = 'unknown'
        AND quantity_on_hand IS NULL
        AND last_verified_at IS NULL
        AND is_available_for_pickup IS FALSE
    ) OR (
        stock_status = 'out_of_stock'
        AND quantity_on_hand IS NOT NULL
        AND quantity_on_hand = 0
        AND last_verified_at IS NOT NULL
        AND is_available_for_pickup IS FALSE
    ) OR (
        stock_status = 'low_stock'
        AND quantity_on_hand IS NOT NULL
        AND quantity_on_hand > 0
        AND last_verified_at IS NOT NULL
        AND is_available_for_pickup IS NOT NULL
    ) OR (
        stock_status = 'in_stock'
        AND (quantity_on_hand IS NULL OR quantity_on_hand > 0)
        AND last_verified_at IS NOT NULL
        AND is_available_for_pickup IS NOT NULL
    )
) IS TRUE)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE pharmacy_inventory ADD CONSTRAINT pharmacy_inventory_pickup_status_check CHECK ((
    is_available_for_pickup IS NOT NULL
    AND (
        is_available_for_pickup IS FALSE
        OR stock_status IN ('in_stock', 'low_stock')
    )
) IS TRUE)
SQL);
        DB::statement('CREATE INDEX pharmacy_inventory_medicine_pickup_index ON pharmacy_inventory (medicine_id, is_available_for_pickup)');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.medifind_guard_pharmacy_inventory()
RETURNS trigger
LANGUAGE plpgsql
AS $fn$
DECLARE
    medicine_status text;
    controlled boolean;
BEGIN
    SELECT status, controlled_drug
    INTO medicine_status, controlled
    FROM medicines
    WHERE id = NEW.medicine_id;

    IF medicine_status IS DISTINCT FROM 'active' THEN
        IF TG_OP = 'INSERT' OR NEW.is_available_for_pickup IS TRUE THEN
            RAISE EXCEPTION 'Inventory requires an active medicine with a verified identity';
        END IF;

        IF OLD.is_available_for_pickup IS NOT TRUE
            OR NEW.stock_status IS DISTINCT FROM OLD.stock_status
            OR NEW.quantity_on_hand IS DISTINCT FROM OLD.quantity_on_hand
            OR NEW.unit_price_paisa IS DISTINCT FROM OLD.unit_price_paisa
            OR NEW.pharmacy_id IS DISTINCT FROM OLD.pharmacy_id
            OR NEW.medicine_id IS DISTINCT FROM OLD.medicine_id
            OR NEW.last_verified_at IS DISTINCT FROM OLD.last_verified_at
            OR NEW.updated_by IS DISTINCT FROM OLD.updated_by THEN
            RAISE EXCEPTION 'Inventory requires an active medicine with a verified identity';
        END IF;
    END IF;

    IF controlled IS TRUE AND NEW.is_available_for_pickup IS TRUE THEN
        RAISE EXCEPTION 'Controlled medicines are not available for pickup';
    END IF;

    RETURN NEW;
END;
$fn$;

CREATE TRIGGER medifind_guard_pharmacy_inventory
BEFORE INSERT OR UPDATE ON pharmacy_inventory
FOR EACH ROW
EXECUTE FUNCTION public.medifind_guard_pharmacy_inventory();

CREATE OR REPLACE FUNCTION public.medifind_clear_controlled_pickup()
RETURNS trigger
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF (NEW.controlled_drug IS TRUE AND OLD.controlled_drug IS DISTINCT FROM TRUE)
        OR (NEW.status IS DISTINCT FROM 'active' AND OLD.status IS NOT DISTINCT FROM 'active') THEN
        UPDATE pharmacy_inventory
        SET is_available_for_pickup = false,
            version = version + 1,
            updated_at = CURRENT_TIMESTAMP
        WHERE medicine_id = NEW.id
          AND is_available_for_pickup IS TRUE;
    END IF;

    RETURN NEW;
END;
$fn$;

CREATE TRIGGER medifind_clear_ineligible_pickup
AFTER UPDATE OF controlled_drug, status ON medicines
FOR EACH ROW
EXECUTE FUNCTION public.medifind_clear_controlled_pickup();

CREATE OR REPLACE FUNCTION public.medifind_reject_controlled_pickup()
RETURNS trigger
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM pharmacy_inventory AS inventory
        INNER JOIN medicines ON medicines.id = inventory.medicine_id
        WHERE inventory.is_available_for_pickup IS TRUE
          AND (
            medicines.controlled_drug IS TRUE
            OR medicines.status IS DISTINCT FROM 'active'
          )
    ) THEN
        RAISE EXCEPTION 'Pickup is unavailable for an inactive or controlled medicine';
    END IF;

    RETURN NULL;
END;
$fn$;

CREATE CONSTRAINT TRIGGER medifind_reject_controlled_pickup_inventory
AFTER INSERT OR UPDATE ON pharmacy_inventory
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
EXECUTE FUNCTION public.medifind_reject_controlled_pickup();

CREATE CONSTRAINT TRIGGER medifind_reject_controlled_pickup_medicine
AFTER UPDATE OF controlled_drug, status ON medicines
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
EXECUTE FUNCTION public.medifind_reject_controlled_pickup();
SQL);

        Schema::create('audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 80);
            $table->string('subject_type', 80);
            $table->uuid('subject_id');
            $table->foreignUuid('pharmacy_id')->nullable()->constrained('pharmacies')->nullOnDelete();
            $table->jsonb('metadata')->default('{}');
            $table->uuid('request_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE audit_events ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('CREATE INDEX audit_events_subject_index ON audit_events (subject_type, subject_id, created_at)');
        DB::statement('CREATE INDEX audit_events_pharmacy_index ON audit_events (pharmacy_id, created_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS medifind_reject_controlled_pickup_medicine ON medicines;
DROP TRIGGER IF EXISTS medifind_clear_ineligible_pickup ON medicines;
DROP TRIGGER IF EXISTS medifind_clear_controlled_pickup ON medicines;
DROP TRIGGER IF EXISTS medifind_reject_controlled_pickup_inventory ON pharmacy_inventory;
DROP TRIGGER IF EXISTS medifind_guard_pharmacy_inventory ON pharmacy_inventory;
DROP FUNCTION IF EXISTS public.medifind_reject_controlled_pickup();
DROP FUNCTION IF EXISTS public.medifind_clear_controlled_pickup();
DROP FUNCTION IF EXISTS public.medifind_guard_pharmacy_inventory();
SQL);
        Schema::dropIfExists('pharmacy_inventory');
    }
};
