<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('public_id')->unique();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->string('object_key', 180)->unique();
            $table->string('display_name', 120);
            $table->string('mime_type', 80);
            $table->unsignedBigInteger('byte_size');
            $table->char('sha256', 64);
            $table->string('extraction_state', 32);
            $table->unsignedInteger('extraction_version')->default(1);
            $table->unsignedInteger('content_version')->default(1);
            $table->string('share_state', 32)->default('none');
            $table->string('share_mode', 32)->nullable();
            $table->unsignedInteger('shared_content_version')->nullable();
            $table->foreignUuid('pharmacy_id')->nullable()->constrained('pharmacies')->restrictOnDelete();
            $table->string('failure_code', 64)->nullable();
            $table->string('object_state', 32)->default('stored');
            $table->uuid('claim_token')->nullable();
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('deleted_at')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE prescriptions ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE prescriptions ALTER COLUMN public_id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_extraction_state_check CHECK (extraction_state IN ('queued', 'processing', 'completed', 'failed'))");
        DB::statement("ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_share_state_check CHECK (share_state IN ('none', 'pending', 'verified', 'rejected', 'withdrawn'))");
        DB::statement("ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_share_mode_check CHECK (share_mode IS NULL OR share_mode IN ('selected_items', 'full_image'))");
        DB::statement('ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_byte_size_check CHECK (byte_size > 0 AND byte_size <= 8388608)');
        DB::statement(<<<'SQL'
ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_share_shape_check CHECK (
    (share_state = 'none' AND share_mode IS NULL AND shared_content_version IS NULL AND pharmacy_id IS NULL)
    OR (share_state IN ('pending', 'verified', 'rejected', 'withdrawn') AND share_mode IS NOT NULL AND shared_content_version IS NOT NULL AND pharmacy_id IS NOT NULL)
)
SQL);
        DB::statement("ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_object_state_check CHECK (object_state IN ('stored', 'purge_pending', 'purged'))");
        DB::statement(<<<'SQL'
ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_object_lifecycle_check CHECK (
    (deleted_at IS NULL AND object_state = 'stored')
    OR (deleted_at IS NOT NULL AND object_state IN ('purge_pending', 'purged'))
)
SQL);
        DB::statement('CREATE INDEX prescriptions_customer_created_index ON prescriptions (customer_id, created_at)');
        DB::statement("CREATE INDEX prescriptions_pharmacy_pending_index ON prescriptions (pharmacy_id, created_at) WHERE share_state = 'pending' AND deleted_at IS NULL");
        DB::statement("CREATE INDEX prescriptions_purge_pending_index ON prescriptions (id) WHERE object_state = 'purge_pending'");
        DB::statement("CREATE INDEX prescriptions_expired_claim_index ON prescriptions (claimed_at) WHERE extraction_state = 'processing' AND deleted_at IS NULL");

        Schema::create('prescription_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('public_id')->unique();
            $table->foreignUuid('prescription_id')->constrained('prescriptions')->cascadeOnDelete();
            $table->unsignedInteger('extraction_version');
            $table->unsignedSmallInteger('line_index');
            $table->text('extracted_text');
            $table->text('corrected_text')->nullable();
            $table->string('match_status', 32);
            $table->foreignUuid('medicine_id')->nullable()->constrained('medicines')->restrictOnDelete();
            $table->boolean('customer_selected')->default(false);
            $table->foreignUuid('selected_medicine_id')->nullable()->constrained('medicines')->restrictOnDelete();
            $table->char('selected_fingerprint', 64)->nullable();
            $table->timestamps();
            $table->unique(['prescription_id', 'extraction_version', 'line_index']);
        });

        DB::statement('ALTER TABLE prescription_items ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE prescription_items ALTER COLUMN public_id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE prescription_items ADD CONSTRAINT prescription_items_match_status_check CHECK (match_status IN ('exact', 'ambiguous', 'unmatched', 'ineligible'))");
        DB::statement(<<<'SQL'
ALTER TABLE prescription_items ADD CONSTRAINT prescription_items_selection_check CHECK (
    customer_selected = false
    OR (selected_medicine_id IS NOT NULL AND match_status = 'exact' AND selected_medicine_id = medicine_id AND selected_fingerprint IS NOT NULL)
)
SQL);

        Schema::create('prescription_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('prescription_id')->constrained('prescriptions')->restrictOnDelete();
            $table->foreignUuid('pharmacy_id')->constrained('pharmacies')->restrictOnDelete();
            $table->foreignUuid('membership_id')->constrained('pharmacy_memberships')->restrictOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('content_version');
            $table->unsignedInteger('extraction_version');
            $table->string('decision', 32);
            $table->text('reason')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE prescription_reviews ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE prescription_reviews ADD CONSTRAINT prescription_reviews_decision_check CHECK (decision IN ('verified', 'rejected'))");
        DB::statement("ALTER TABLE prescription_reviews ADD CONSTRAINT prescription_reviews_reason_check CHECK (decision <> 'rejected' OR (reason IS NOT NULL AND length(btrim(reason)) > 0))");
        DB::statement('CREATE INDEX prescription_reviews_version_index ON prescription_reviews (prescription_id, content_version, created_at)');

        Schema::create('prescription_object_holds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('object_key', 180)->unique();
            $table->string('status', 32);
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE prescription_object_holds ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE prescription_object_holds ADD CONSTRAINT prescription_object_holds_status_check CHECK (status IN ('open', 'attached', 'purge_pending', 'purged'))");
        DB::statement('CREATE INDEX prescription_object_holds_reclaim_index ON prescription_object_holds (status, created_at)');

        Schema::create('prescription_work_cursors', function (Blueprint $table): void {
            $table->string('name', 64)->primary();
            $table->uuid('position')->nullable();
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_work_cursors');
        Schema::dropIfExists('prescription_object_holds');
        Schema::dropIfExists('prescription_reviews');
        Schema::dropIfExists('prescription_items');
        Schema::dropIfExists('prescriptions');
    }
};
