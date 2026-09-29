<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The exclusive locks, the customer-state check, and the schema drop
     * commit or roll back together. PostgreSQL releases LOCK TABLE at the
     * end of the transaction.
     */
    public $withinTransaction = true;

    public function up(): void
    {
        Schema::create('customer_cart_sequences', function (Blueprint $table): void {
            $table->foreignId('customer_id')->primary()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('last_version');
        });

        DB::statement('ALTER TABLE customer_cart_sequences ADD CONSTRAINT customer_cart_sequences_version_check CHECK (last_version >= 1)');

        Schema::create('customer_carts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('customer_id')->unique()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('pharmacy_id')->constrained('pharmacies')->restrictOnDelete();
            $table->string('request_mode', 40);
            $table->foreignUuid('prescription_id')->nullable()->constrained('prescriptions')->restrictOnDelete();
            $table->text('customer_note')->nullable();
            $table->unsignedInteger('version');
            $table->timestamp('created_at', 6)->nullable();
            $table->timestamp('updated_at', 6)->nullable();
        });

        DB::statement('ALTER TABLE customer_carts ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE customer_carts ADD CONSTRAINT customer_carts_mode_check CHECK (request_mode IN ('catalog_items', 'prescription_selected_items', 'prescription_full_image'))");
        DB::statement('ALTER TABLE customer_carts ADD CONSTRAINT customer_carts_version_check CHECK (version >= 1)');
        DB::statement('ALTER TABLE customer_carts ADD CONSTRAINT customer_carts_note_check CHECK (customer_note IS NULL OR char_length(customer_note) <= 500)');

        Schema::create('customer_cart_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('cart_id')->constrained('customer_carts')->cascadeOnDelete();
            $table->foreignUuid('medicine_id')->constrained('medicines')->restrictOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->unique(['cart_id', 'medicine_id']);
        });

        DB::statement('ALTER TABLE customer_cart_items ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE customer_cart_items ADD CONSTRAINT customer_cart_items_quantity_check CHECK (quantity BETWEEN 1 AND 99)');

        Schema::create('orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('public_id')->unique();
            $table->string('reference', 16)->unique();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('pharmacy_id')->constrained('pharmacies')->restrictOnDelete();
            $table->string('request_mode', 40);
            $table->foreignUuid('prescription_id')->nullable()->constrained('prescriptions')->restrictOnDelete();
            $table->uuid('prescription_public_id')->nullable();
            $table->unsignedInteger('prescription_content_version')->nullable();
            $table->string('prescription_share_mode', 32)->nullable();
            $table->boolean('prescription_usable')->default(true);
            $table->text('customer_note')->nullable();
            $table->string('status', 40);
            $table->string('payment_method', 32);
            $table->string('payment_status', 32);
            $table->unsignedBigInteger('estimate_total_paisa')->nullable();
            $table->timestamp('created_at', 6)->nullable();
            $table->timestamp('updated_at', 6)->nullable();
        });

        DB::statement('ALTER TABLE orders ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE orders ALTER COLUMN public_id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_mode_check CHECK (request_mode IN ('catalog_items', 'prescription_selected_items', 'prescription_full_image'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending_pharmacy_review', 'confirmed', 'ready_for_pickup', 'collected', 'rejected', 'cancelled'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_method_check CHECK (payment_method = 'cash_on_pickup')");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_status_check CHECK (payment_status = 'unpaid')");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_note_check CHECK (customer_note IS NULL OR char_length(customer_note) <= 500)');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_share_mode_check CHECK (prescription_share_mode IS NULL OR prescription_share_mode IN ('selected_items', 'full_image'))");
        DB::statement(<<<'SQL'
ALTER TABLE orders ADD CONSTRAINT orders_prescription_shape_check CHECK (
    (
        prescription_id IS NULL
        AND prescription_public_id IS NULL
        AND prescription_content_version IS NULL
        AND prescription_share_mode IS NULL
    ) OR (
        prescription_id IS NOT NULL
        AND prescription_public_id IS NOT NULL
        AND prescription_content_version IS NOT NULL
        AND prescription_share_mode IS NOT NULL
    )
)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE orders ADD CONSTRAINT orders_estimate_check CHECK (
    (
        request_mode = 'prescription_full_image'
        AND estimate_total_paisa IS NULL
    ) OR (
        request_mode IN ('catalog_items', 'prescription_selected_items')
        AND estimate_total_paisa IS NOT NULL
        AND estimate_total_paisa <= 10000000000
    )
)
SQL);
        DB::statement('CREATE INDEX orders_customer_history_index ON orders (customer_id, created_at DESC, id DESC)');
        DB::statement('CREATE INDEX orders_prescription_usable_index ON orders (prescription_id) WHERE prescription_usable IS TRUE AND prescription_id IS NOT NULL');

        Schema::create('order_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignUuid('medicine_id')->constrained('medicines')->restrictOnDelete();
            $table->string('generic_name', 180);
            $table->string('brand_name', 180)->nullable();
            $table->string('strength', 80)->nullable();
            $table->string('dosage_form', 80);
            $table->string('manufacturer', 180);
            $table->boolean('prescription_required');
            $table->unsignedSmallInteger('quantity');
            $table->unsignedBigInteger('unit_price_paisa');
            $table->unsignedBigInteger('line_estimate_paisa');
            $table->uuid('inventory_id');
            $table->unsignedInteger('inventory_version');
            $table->timestampTz('inventory_verified_at');
            $table->unique(['order_id', 'medicine_id']);
        });

        DB::statement('ALTER TABLE order_items ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(<<<'SQL'
ALTER TABLE order_items ADD CONSTRAINT order_items_money_check CHECK (
    quantity BETWEEN 1 AND 99
    AND unit_price_paisa <= 1000000000
    AND line_estimate_paisa = unit_price_paisa * quantity
    AND inventory_version >= 1
)
SQL);

        Schema::create('order_status_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('status', 40);
            $table->string('event', 40);
            $table->string('cause', 40)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('created_at', 6);
        });

        DB::statement('ALTER TABLE order_status_events ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE order_status_events ADD CONSTRAINT order_status_events_status_check CHECK (status IN ('pending_pharmacy_review', 'confirmed', 'ready_for_pickup', 'collected', 'rejected', 'cancelled'))");
        DB::statement("ALTER TABLE order_status_events ADD CONSTRAINT order_status_events_event_check CHECK (event IN ('submitted', 'cancelled', 'prescription_unusable'))");
        DB::statement(<<<'SQL'
ALTER TABLE order_status_events ADD CONSTRAINT order_status_events_cause_check CHECK (
    cause IS NULL OR cause IN (
        'prescription_corrected',
        'prescription_replaced',
        'prescription_withdrawn',
        'prescription_deleted',
        'prescription_rejected',
        'prescription_reshared',
        'prescription_stale'
    )
)
SQL);
        DB::statement('ALTER TABLE order_status_events ADD CONSTRAINT order_status_events_reason_check CHECK (reason IS NULL OR char_length(reason) <= 200)');
        DB::statement('CREATE INDEX order_status_events_order_index ON order_status_events (order_id, created_at, id)');

        Schema::create('order_submissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->char('key_hash', 64);
            $table->char('fingerprint', 64);
            $table->unsignedInteger('cart_version');
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->timestamp('created_at', 6);
            $table->unique(['customer_id', 'key_hash'], 'order_submissions_customer_key_unique');
        });

        DB::statement('ALTER TABLE order_submissions ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE order_submissions ADD CONSTRAINT order_submissions_version_check CHECK (cart_version >= 1)');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.medifind_guard_cart_item()
RETURNS trigger
LANGUAGE plpgsql
AS $fn$
DECLARE
    mode text;
    item_count integer;
BEGIN
    SELECT request_mode INTO mode FROM customer_carts WHERE id = NEW.cart_id;
    IF mode = 'prescription_full_image' THEN
        RAISE EXCEPTION 'full image cart cannot have catalog lines';
    END IF;
    SELECT COUNT(*) INTO item_count FROM customer_cart_items WHERE cart_id = NEW.cart_id;
    IF item_count > 10 THEN
        RAISE EXCEPTION 'cart item limit';
    END IF;
    RETURN NEW;
END;
$fn$;

CREATE TRIGGER medifind_guard_cart_item
AFTER INSERT ON customer_cart_items
FOR EACH ROW
EXECUTE FUNCTION public.medifind_guard_cart_item();

CREATE OR REPLACE FUNCTION public.medifind_guard_order_item()
RETURNS trigger
LANGUAGE plpgsql
AS $fn$
DECLARE
    mode text;
    item_count integer;
BEGIN
    SELECT request_mode INTO mode FROM orders WHERE id = NEW.order_id;
    IF mode = 'prescription_full_image' THEN
        RAISE EXCEPTION 'full image order cannot have catalog lines';
    END IF;
    SELECT COUNT(*) INTO item_count FROM order_items WHERE order_id = NEW.order_id;
    IF item_count > 10 THEN
        RAISE EXCEPTION 'order item limit';
    END IF;
    RETURN NEW;
END;
$fn$;

CREATE TRIGGER medifind_guard_order_item
AFTER INSERT ON order_items
FOR EACH ROW
EXECUTE FUNCTION public.medifind_guard_order_item();

CREATE OR REPLACE FUNCTION public.medifind_order_items_present()
RETURNS trigger
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF NEW.request_mode = 'prescription_full_image' THEN
        IF EXISTS (SELECT 1 FROM order_items WHERE order_id = NEW.id) THEN
            RAISE EXCEPTION 'full image order cannot have catalog lines';
        END IF;
    ELSIF NOT EXISTS (SELECT 1 FROM order_items WHERE order_id = NEW.id) THEN
        RAISE EXCEPTION 'order requires catalog lines';
    END IF;
    RETURN NEW;
END;
$fn$;

CREATE CONSTRAINT TRIGGER medifind_order_items_present
AFTER INSERT ON orders
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
EXECUTE FUNCTION public.medifind_order_items_present();

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

CREATE TRIGGER medifind_guard_order_snapshot
BEFORE UPDATE ON orders
FOR EACH ROW
EXECUTE FUNCTION public.medifind_guard_order_snapshot();

CREATE OR REPLACE FUNCTION public.medifind_reject_order_event_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $fn$
BEGIN
    RAISE EXCEPTION 'order status events are immutable';
END;
$fn$;

CREATE TRIGGER medifind_reject_order_event_update
BEFORE UPDATE ON order_status_events
FOR EACH ROW
EXECUTE FUNCTION public.medifind_reject_order_event_mutation();

CREATE TRIGGER medifind_reject_order_event_delete
BEFORE DELETE ON order_status_events
FOR EACH ROW
EXECUTE FUNCTION public.medifind_reject_order_event_mutation();

CREATE OR REPLACE FUNCTION public.medifind_reject_order_item_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $fn$
BEGIN
    RAISE EXCEPTION 'order items are immutable';
END;
$fn$;

CREATE TRIGGER medifind_reject_order_item_update
BEFORE UPDATE ON order_items
FOR EACH ROW
EXECUTE FUNCTION public.medifind_reject_order_item_mutation();

CREATE TRIGGER medifind_reject_order_item_delete
BEFORE DELETE ON order_items
FOR EACH ROW
EXECUTE FUNCTION public.medifind_reject_order_item_mutation();
SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
LOCK TABLE
    order_submissions,
    customer_carts,
    customer_cart_items,
    customer_cart_sequences,
    orders,
    order_items,
    order_status_events
IN ACCESS EXCLUSIVE MODE
SQL);

        $existing = (int) DB::scalar(<<<'SQL'
select case when exists (select 1 from order_submissions)
    or exists (select 1 from customer_carts)
    or exists (select 1 from customer_cart_items)
    or exists (select 1 from customer_cart_sequences)
    or exists (select 1 from orders)
    or exists (select 1 from order_items)
    or exists (select 1 from order_status_events)
then 1 else 0 end
SQL);

        if ($existing === 1) {
            throw new RuntimeException('Cannot roll back pickup ordering while customer pickup state exists.');
        }

        Schema::dropIfExists('order_submissions');
        Schema::dropIfExists('order_status_events');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('customer_cart_items');
        Schema::dropIfExists('customer_carts');
        Schema::dropIfExists('customer_cart_sequences');

        DB::unprepared(<<<'SQL'
DROP FUNCTION IF EXISTS public.medifind_guard_cart_item();
DROP FUNCTION IF EXISTS public.medifind_guard_order_item();
DROP FUNCTION IF EXISTS public.medifind_order_items_present();
DROP FUNCTION IF EXISTS public.medifind_guard_order_snapshot();
DROP FUNCTION IF EXISTS public.medifind_reject_order_event_mutation();
DROP FUNCTION IF EXISTS public.medifind_reject_order_item_mutation();
SQL);
    }
};
