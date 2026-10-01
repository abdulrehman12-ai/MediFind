<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('confirmed_total_paisa')->nullable();
            $table->unsignedBigInteger('cash_collected_paisa')->nullable();
            $table->timestampTz('pickup_window_starts_at', 6)->nullable();
            $table->timestampTz('pickup_window_ends_at', 6)->nullable();
            $table->unsignedInteger('pickup_grace_seconds')->nullable();
            $table->timestampTz('collect_until', 6)->nullable();
        });

        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_status_check');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_payment_status_check');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending_pharmacy_review', 'confirmed', 'ready_for_pickup', 'collected', 'rejected', 'cancelled', 'not_collected', 'unfulfillable'))");
        DB::statement(<<<'SQL'
ALTER TABLE orders ADD CONSTRAINT orders_payment_state_check CHECK (
    (
        status <> 'collected'
        AND payment_status = 'unpaid'
        AND cash_collected_paisa IS NULL
    ) OR (
        status = 'collected'
        AND confirmed_total_paisa > 0
        AND payment_status = 'paid'
        AND cash_collected_paisa = confirmed_total_paisa
        AND payment_method = 'cash_on_pickup'
    ) OR (
        status = 'collected'
        AND confirmed_total_paisa = 0
        AND payment_status = 'no_payment_due'
        AND cash_collected_paisa = 0
        AND payment_method = 'cash_on_pickup'
    )
)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE orders ADD CONSTRAINT orders_confirmed_total_check CHECK (
    confirmed_total_paisa IS NULL
    OR confirmed_total_paisa <= 10000000000
)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE orders ADD CONSTRAINT orders_confirmation_shape_check CHECK (
    (
        status IN ('pending_pharmacy_review', 'rejected')
        AND confirmed_total_paisa IS NULL
    ) OR (
        status = 'cancelled'
    ) OR (
        status IN ('confirmed', 'ready_for_pickup', 'collected', 'not_collected', 'unfulfillable')
        AND confirmed_total_paisa IS NOT NULL
    )
)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE orders ADD CONSTRAINT orders_pickup_window_check CHECK (
    (
        status IN ('pending_pharmacy_review', 'confirmed', 'rejected', 'cancelled')
        AND pickup_window_starts_at IS NULL
        AND pickup_window_ends_at IS NULL
        AND pickup_grace_seconds IS NULL
        AND collect_until IS NULL
    ) OR (
        status IN ('ready_for_pickup', 'collected', 'not_collected')
        AND pickup_window_starts_at IS NOT NULL
        AND pickup_window_ends_at IS NOT NULL
        AND pickup_grace_seconds IS NOT NULL
        AND collect_until IS NOT NULL
        AND pickup_window_ends_at > pickup_window_starts_at
        AND pickup_grace_seconds > 0
        AND collect_until = pickup_window_ends_at + make_interval(secs => pickup_grace_seconds::double precision)
    ) OR (
        status = 'unfulfillable'
        AND (
            (
                pickup_window_starts_at IS NULL
                AND pickup_window_ends_at IS NULL
                AND pickup_grace_seconds IS NULL
                AND collect_until IS NULL
            ) OR (
                pickup_window_starts_at IS NOT NULL
                AND pickup_window_ends_at IS NOT NULL
                AND pickup_grace_seconds IS NOT NULL
                AND collect_until IS NOT NULL
                AND pickup_window_ends_at > pickup_window_starts_at
                AND pickup_grace_seconds > 0
                AND collect_until = pickup_window_ends_at + make_interval(secs => pickup_grace_seconds::double precision)
            )
        )
    )
)
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.medifind_guard_order_snapshot()
RETURNS trigger
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF NEW.customer_note IS DISTINCT FROM OLD.customer_note
        OR NEW.customer_id IS DISTINCT FROM OLD.customer_id
        OR NEW.pharmacy_id IS DISTINCT FROM OLD.pharmacy_id
        OR NEW.request_mode IS DISTINCT FROM OLD.request_mode
        OR NEW.prescription_id IS DISTINCT FROM OLD.prescription_id
        OR NEW.prescription_public_id IS DISTINCT FROM OLD.prescription_public_id
        OR NEW.prescription_content_version IS DISTINCT FROM OLD.prescription_content_version
        OR NEW.prescription_share_mode IS DISTINCT FROM OLD.prescription_share_mode
        OR NEW.estimate_total_paisa IS DISTINCT FROM OLD.estimate_total_paisa
        OR NEW.payment_method IS DISTINCT FROM OLD.payment_method
        OR NEW.public_id IS DISTINCT FROM OLD.public_id
        OR NEW.reference IS DISTINCT FROM OLD.reference
    THEN
        RAISE EXCEPTION 'order snapshot is immutable';
    END IF;

    IF OLD.confirmed_total_paisa IS NOT NULL
        AND NEW.confirmed_total_paisa IS DISTINCT FROM OLD.confirmed_total_paisa
    THEN
        RAISE EXCEPTION 'order snapshot is immutable';
    END IF;

    IF OLD.pickup_window_starts_at IS NOT NULL AND (
        NEW.pickup_window_starts_at IS DISTINCT FROM OLD.pickup_window_starts_at
        OR NEW.pickup_window_ends_at IS DISTINCT FROM OLD.pickup_window_ends_at
        OR NEW.pickup_grace_seconds IS DISTINCT FROM OLD.pickup_grace_seconds
        OR NEW.collect_until IS DISTINCT FROM OLD.collect_until
    ) THEN
        RAISE EXCEPTION 'order snapshot is immutable';
    END IF;

    IF OLD.cash_collected_paisa IS NOT NULL
        AND NEW.cash_collected_paisa IS DISTINCT FROM OLD.cash_collected_paisa
    THEN
        RAISE EXCEPTION 'order snapshot is immutable';
    END IF;

    IF OLD.payment_status IS DISTINCT FROM NEW.payment_status AND NOT (
        OLD.payment_status = 'unpaid'
        AND NEW.payment_status IN ('paid', 'no_payment_due')
        AND OLD.status = 'ready_for_pickup'
        AND NEW.status = 'collected'
    ) THEN
        RAISE EXCEPTION 'order snapshot is immutable';
    END IF;

    RETURN NEW;
END;
$fn$;
SQL);

        DB::statement('ALTER TABLE order_status_events DROP CONSTRAINT order_status_events_status_check');
        DB::statement('ALTER TABLE order_status_events DROP CONSTRAINT order_status_events_event_check');
        DB::statement("ALTER TABLE order_status_events ADD CONSTRAINT order_status_events_status_check CHECK (status IN ('pending_pharmacy_review', 'confirmed', 'ready_for_pickup', 'collected', 'rejected', 'cancelled', 'not_collected', 'unfulfillable'))");
        DB::statement("ALTER TABLE order_status_events ADD CONSTRAINT order_status_events_event_check CHECK (event IN ('submitted', 'cancelled', 'prescription_unusable', 'confirmed', 'rejected', 'ready_for_pickup', 'collected', 'not_collected'))");
        DB::statement("CREATE UNIQUE INDEX order_status_events_prescription_unusable_unique ON order_status_events (order_id) WHERE event = 'prescription_unusable'");

        Schema::create('order_quotes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('state', 32);
            $table->unsignedBigInteger('total_paisa');
            $table->unsignedInteger('prescription_content_version')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('expires_at', 6);
            $table->timestamp('created_at', 6);
            $table->unique(['order_id', 'version']);
        });
        DB::statement('ALTER TABLE order_quotes ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE order_quotes ADD CONSTRAINT order_quotes_state_check CHECK (state IN ('open', 'accepted', 'superseded', 'invalid'))");
        DB::statement('ALTER TABLE order_quotes ADD CONSTRAINT order_quotes_version_check CHECK (version >= 1)');
        DB::statement('ALTER TABLE order_quotes ADD CONSTRAINT order_quotes_total_check CHECK (total_paisa <= 10000000000)');
        DB::statement("CREATE UNIQUE INDEX order_quotes_one_open ON order_quotes (order_id) WHERE state = 'open'");
        DB::statement("CREATE UNIQUE INDEX order_quotes_one_accepted ON order_quotes (order_id) WHERE state = 'accepted'");

        Schema::create('order_quote_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('quote_id')->constrained('order_quotes')->restrictOnDelete();
            $table->foreignUuid('medicine_id')->constrained('medicines')->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->unsignedSmallInteger('quantity');
            $table->unsignedBigInteger('unit_price_paisa');
            $table->unsignedBigInteger('line_total_paisa');
            $table->string('generic_name', 180);
            $table->string('brand_name', 180)->nullable();
            $table->string('strength', 80)->nullable();
            $table->string('dosage_form', 80);
            $table->string('manufacturer', 180);
            $table->unique(['quote_id', 'medicine_id']);
            $table->unique(['quote_id', 'position']);
        });
        DB::statement('ALTER TABLE order_quote_lines ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(<<<'SQL'
ALTER TABLE order_quote_lines ADD CONSTRAINT order_quote_lines_money_check CHECK (
    quantity BETWEEN 1 AND 99
    AND unit_price_paisa <= 1000000000
    AND line_total_paisa = unit_price_paisa * quantity
    AND position >= 1
)
SQL);

        Schema::create('order_quote_acceptances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('quote_id')->unique()->constrained('order_quotes')->restrictOnDelete();
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('quote_version');
            $table->unsignedBigInteger('total_paisa');
            $table->unsignedInteger('prescription_content_version')->nullable();
            $table->char('line_fingerprint', 64);
            $table->timestamp('accepted_at', 6);
        });
        DB::statement('ALTER TABLE order_quote_acceptances ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE order_quote_acceptances ADD CONSTRAINT order_quote_acceptances_version_check CHECK (quote_version >= 1)');
        DB::statement('ALTER TABLE order_quote_acceptances ADD CONSTRAINT order_quote_acceptances_total_check CHECK (total_paisa <= 10000000000)');

        Schema::create('order_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignUuid('inventory_id')->constrained('pharmacy_inventory')->restrictOnDelete();
            $table->foreignUuid('medicine_id')->constrained('medicines')->restrictOnDelete();
            $table->foreignUuid('order_item_id')->nullable()->constrained('order_items')->restrictOnDelete();
            $table->foreignUuid('quote_line_id')->nullable()->constrained('order_quote_lines')->restrictOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->string('state', 32);
            $table->timestamp('created_at', 6);
            $table->unique(['order_id', 'medicine_id']);
        });
        DB::statement('ALTER TABLE order_reservations ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE order_reservations ADD CONSTRAINT order_reservations_state_check CHECK (state IN ('active', 'released', 'consumed'))");
        DB::statement('ALTER TABLE order_reservations ADD CONSTRAINT order_reservations_quantity_check CHECK (quantity BETWEEN 1 AND 99)');
        DB::statement(<<<'SQL'
ALTER TABLE order_reservations ADD CONSTRAINT order_reservations_line_check CHECK (
    (
        order_item_id IS NOT NULL AND quote_line_id IS NULL
    ) OR (
        order_item_id IS NULL AND quote_line_id IS NOT NULL
    )
)
SQL);
        DB::statement('CREATE UNIQUE INDEX order_reservations_order_item_unique ON order_reservations (order_item_id) WHERE order_item_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX order_reservations_quote_line_unique ON order_reservations (quote_line_id) WHERE quote_line_id IS NOT NULL');
        DB::statement("CREATE INDEX order_reservations_active_inventory_index ON order_reservations (inventory_id) WHERE state = 'active'");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.medifind_reject_quote_line_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $fn$
BEGIN
    RAISE EXCEPTION 'quote lines are immutable';
END;
$fn$;

CREATE TRIGGER medifind_reject_quote_line_update
BEFORE UPDATE ON order_quote_lines
FOR EACH ROW
EXECUTE FUNCTION public.medifind_reject_quote_line_mutation();

CREATE TRIGGER medifind_reject_quote_line_delete
BEFORE DELETE ON order_quote_lines
FOR EACH ROW
EXECUTE FUNCTION public.medifind_reject_quote_line_mutation();

CREATE OR REPLACE FUNCTION public.medifind_guard_quote_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF NEW.order_id IS DISTINCT FROM OLD.order_id
        OR NEW.version IS DISTINCT FROM OLD.version
        OR NEW.total_paisa IS DISTINCT FROM OLD.total_paisa
        OR NEW.prescription_content_version IS DISTINCT FROM OLD.prescription_content_version
        OR NEW.created_by IS DISTINCT FROM OLD.created_by
        OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
        OR NEW.created_at IS DISTINCT FROM OLD.created_at
    THEN
        RAISE EXCEPTION 'quote is immutable';
    END IF;

    IF OLD.state = 'open' AND NEW.state IN ('accepted', 'superseded', 'invalid') THEN
        RETURN NEW;
    END IF;

    IF OLD.state = 'accepted' AND NEW.state IN ('superseded', 'invalid') THEN
        RETURN NEW;
    END IF;

    IF OLD.state IS NOT DISTINCT FROM NEW.state THEN
        RETURN NEW;
    END IF;

    RAISE EXCEPTION 'quote is immutable';
END;
$fn$;

CREATE TRIGGER medifind_guard_quote_mutation
BEFORE UPDATE ON order_quotes
FOR EACH ROW
EXECUTE FUNCTION public.medifind_guard_quote_mutation();

CREATE OR REPLACE FUNCTION public.medifind_reject_quote_delete()
RETURNS trigger
LANGUAGE plpgsql
AS $fn$
BEGIN
    RAISE EXCEPTION 'quote is immutable';
END;
$fn$;

CREATE TRIGGER medifind_reject_quote_delete
BEFORE DELETE ON order_quotes
FOR EACH ROW
EXECUTE FUNCTION public.medifind_reject_quote_delete();

CREATE OR REPLACE FUNCTION public.medifind_reject_acceptance_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $fn$
BEGIN
    RAISE EXCEPTION 'quote acceptance is immutable';
END;
$fn$;

CREATE TRIGGER medifind_reject_acceptance_update
BEFORE UPDATE ON order_quote_acceptances
FOR EACH ROW
EXECUTE FUNCTION public.medifind_reject_acceptance_mutation();

CREATE TRIGGER medifind_reject_acceptance_delete
BEFORE DELETE ON order_quote_acceptances
FOR EACH ROW
EXECUTE FUNCTION public.medifind_reject_acceptance_mutation();

CREATE OR REPLACE FUNCTION public.medifind_guard_reservation_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'reservation is immutable';
    END IF;

    IF NEW.order_id IS DISTINCT FROM OLD.order_id
        OR NEW.inventory_id IS DISTINCT FROM OLD.inventory_id
        OR NEW.medicine_id IS DISTINCT FROM OLD.medicine_id
        OR NEW.order_item_id IS DISTINCT FROM OLD.order_item_id
        OR NEW.quote_line_id IS DISTINCT FROM OLD.quote_line_id
        OR NEW.quantity IS DISTINCT FROM OLD.quantity
        OR NEW.created_at IS DISTINCT FROM OLD.created_at
    THEN
        RAISE EXCEPTION 'reservation is immutable';
    END IF;

    IF OLD.state = 'active' AND NEW.state IN ('released', 'consumed') THEN
        RETURN NEW;
    END IF;

    IF OLD.state IS NOT DISTINCT FROM NEW.state THEN
        RETURN NEW;
    END IF;

    RAISE EXCEPTION 'reservation is immutable';
END;
$fn$;

CREATE TRIGGER medifind_guard_reservation_mutation
BEFORE UPDATE ON order_reservations
FOR EACH ROW
EXECUTE FUNCTION public.medifind_guard_reservation_mutation();

CREATE TRIGGER medifind_reject_reservation_delete
BEFORE DELETE ON order_reservations
FOR EACH ROW
EXECUTE FUNCTION public.medifind_guard_reservation_mutation();

CREATE OR REPLACE FUNCTION public.medifind_guard_active_reservations()
RETURNS trigger
LANGUAGE plpgsql
AS $fn$
DECLARE
    inventory uuid;
    on_hand integer;
    reserved integer;
BEGIN
    inventory := COALESCE(NEW.inventory_id, OLD.inventory_id);
    PERFORM 1 FROM pharmacy_inventory WHERE id = inventory FOR UPDATE;

    SELECT quantity_on_hand INTO on_hand
    FROM pharmacy_inventory
    WHERE id = inventory;

    SELECT COALESCE(SUM(quantity), 0) INTO reserved
    FROM order_reservations
    WHERE inventory_id = inventory
      AND state = 'active';

    IF reserved > 0 AND on_hand IS NULL THEN
        RAISE EXCEPTION 'active reservations require numeric on-hand quantity';
    END IF;

    IF on_hand IS NOT NULL AND reserved > on_hand THEN
        RAISE EXCEPTION 'active reservations exceed quantity_on_hand';
    END IF;

    RETURN NULL;
END;
$fn$;

CREATE CONSTRAINT TRIGGER medifind_guard_active_reservations
AFTER INSERT OR UPDATE OR DELETE ON order_reservations
DEFERRABLE INITIALLY IMMEDIATE
FOR EACH ROW
EXECUTE FUNCTION public.medifind_guard_active_reservations();

CREATE OR REPLACE FUNCTION public.medifind_guard_inventory_reservations()
RETURNS trigger
LANGUAGE plpgsql
AS $fn$
DECLARE
    reserved integer;
BEGIN
    IF NEW.quantity_on_hand IS NOT DISTINCT FROM OLD.quantity_on_hand THEN
        RETURN NEW;
    END IF;

    PERFORM 1
    FROM order_reservations
    WHERE inventory_id = NEW.id
    ORDER BY id
    FOR UPDATE;

    SELECT COALESCE(SUM(quantity), 0) INTO reserved
    FROM order_reservations
    WHERE inventory_id = NEW.id
      AND state = 'active';

    IF reserved > 0 AND NEW.quantity_on_hand IS NULL THEN
        RAISE EXCEPTION 'active reservations require numeric on-hand quantity';
    END IF;

    IF NEW.quantity_on_hand IS NOT NULL AND reserved > NEW.quantity_on_hand THEN
        RAISE EXCEPTION 'active reservations exceed quantity_on_hand';
    END IF;

    RETURN NEW;
END;
$fn$;

CREATE TRIGGER medifind_guard_inventory_reservations
BEFORE UPDATE ON pharmacy_inventory
FOR EACH ROW
EXECUTE FUNCTION public.medifind_guard_inventory_reservations();
SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
LOCK TABLE
    order_quote_acceptances,
    order_quote_lines,
    order_quotes,
    order_reservations,
    orders,
    order_items,
    order_status_events,
    pharmacy_inventory
IN ACCESS EXCLUSIVE MODE
SQL);

        $existing = (int) DB::scalar(<<<'SQL'
select case when exists (select 1 from order_quotes)
    or exists (select 1 from order_quote_lines)
    or exists (select 1 from order_quote_acceptances)
    or exists (select 1 from order_reservations)
    or exists (
        select 1 from orders
        where status in ('not_collected', 'unfulfillable')
            or confirmed_total_paisa is not null
            or cash_collected_paisa is not null
            or payment_status <> 'unpaid'
            or pickup_window_starts_at is not null
    )
then 1 else 0 end
SQL);

        if ($existing === 1) {
            throw new RuntimeException('Cannot roll back pharmacy fulfillment while quotes, reservations, or fulfillment records exist.');
        }

        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS medifind_guard_inventory_reservations ON pharmacy_inventory;
DROP TRIGGER IF EXISTS medifind_guard_active_reservations ON order_reservations;
DROP TRIGGER IF EXISTS medifind_reject_reservation_delete ON order_reservations;
DROP TRIGGER IF EXISTS medifind_guard_reservation_mutation ON order_reservations;
DROP TRIGGER IF EXISTS medifind_reject_acceptance_delete ON order_quote_acceptances;
DROP TRIGGER IF EXISTS medifind_reject_acceptance_update ON order_quote_acceptances;
DROP TRIGGER IF EXISTS medifind_reject_quote_delete ON order_quotes;
DROP TRIGGER IF EXISTS medifind_guard_quote_mutation ON order_quotes;
DROP TRIGGER IF EXISTS medifind_reject_quote_line_delete ON order_quote_lines;
DROP TRIGGER IF EXISTS medifind_reject_quote_line_update ON order_quote_lines;
DROP FUNCTION IF EXISTS public.medifind_guard_inventory_reservations();
DROP FUNCTION IF EXISTS public.medifind_guard_active_reservations();
DROP FUNCTION IF EXISTS public.medifind_guard_reservation_mutation();
DROP FUNCTION IF EXISTS public.medifind_reject_acceptance_mutation();
DROP FUNCTION IF EXISTS public.medifind_reject_quote_delete();
DROP FUNCTION IF EXISTS public.medifind_guard_quote_mutation();
DROP FUNCTION IF EXISTS public.medifind_reject_quote_line_mutation();
SQL);

        Schema::dropIfExists('order_reservations');
        Schema::dropIfExists('order_quote_acceptances');
        Schema::dropIfExists('order_quote_lines');
        Schema::dropIfExists('order_quotes');

        DB::statement('DROP INDEX IF EXISTS order_status_events_prescription_unusable_unique');
        DB::statement('ALTER TABLE order_status_events DROP CONSTRAINT order_status_events_event_check');
        DB::statement('ALTER TABLE order_status_events DROP CONSTRAINT order_status_events_status_check');
        DB::statement("ALTER TABLE order_status_events ADD CONSTRAINT order_status_events_status_check CHECK (status IN ('pending_pharmacy_review', 'confirmed', 'ready_for_pickup', 'collected', 'rejected', 'cancelled'))");
        DB::statement("ALTER TABLE order_status_events ADD CONSTRAINT order_status_events_event_check CHECK (event IN ('submitted', 'cancelled', 'prescription_unusable'))");

        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_pickup_window_check');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_confirmation_shape_check');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_confirmed_total_check');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_payment_state_check');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_status_check');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending_pharmacy_review', 'confirmed', 'ready_for_pickup', 'collected', 'rejected', 'cancelled'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_status_check CHECK (payment_status = 'unpaid')");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.medifind_guard_order_snapshot()
RETURNS trigger
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF NEW.customer_note IS DISTINCT FROM OLD.customer_note
        OR NEW.customer_id IS DISTINCT FROM OLD.customer_id
        OR NEW.pharmacy_id IS DISTINCT FROM OLD.pharmacy_id
        OR NEW.request_mode IS DISTINCT FROM OLD.request_mode
        OR NEW.prescription_id IS DISTINCT FROM OLD.prescription_id
        OR NEW.prescription_public_id IS DISTINCT FROM OLD.prescription_public_id
        OR NEW.prescription_content_version IS DISTINCT FROM OLD.prescription_content_version
        OR NEW.prescription_share_mode IS DISTINCT FROM OLD.prescription_share_mode
        OR NEW.estimate_total_paisa IS DISTINCT FROM OLD.estimate_total_paisa
        OR NEW.payment_method IS DISTINCT FROM OLD.payment_method
        OR NEW.payment_status IS DISTINCT FROM OLD.payment_status
        OR NEW.public_id IS DISTINCT FROM OLD.public_id
        OR NEW.reference IS DISTINCT FROM OLD.reference
    THEN
        RAISE EXCEPTION 'order snapshot is immutable';
    END IF;
    RETURN NEW;
END;
$fn$;
SQL);

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'confirmed_total_paisa',
                'cash_collected_paisa',
                'pickup_window_starts_at',
                'pickup_window_ends_at',
                'pickup_grace_seconds',
                'collect_until',
            ]);
        });
    }
};
