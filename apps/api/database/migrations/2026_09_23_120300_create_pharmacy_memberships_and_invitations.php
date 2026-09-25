<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacy_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('pharmacy_id')->constrained('pharmacies')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('staff_role', 32);
            $table->string('status', 32)->default('active');
            $table->string('license_number', 100)->nullable();
            $table->string('credential_status', 32)->default('unverified');
            $table->foreignId('credential_reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('credential_reviewed_at')->nullable();
            $table->string('credential_verification_method', 64)->nullable();
            $table->text('credential_evidence')->nullable();
            $table->timestamps();
            $table->unique(['pharmacy_id', 'user_id']);
        });

        DB::statement('ALTER TABLE pharmacy_memberships ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE pharmacy_memberships ADD CONSTRAINT pharmacy_memberships_role_check CHECK (staff_role IN ('manager', 'pharmacist', 'inventory_staff'))");
        DB::statement("ALTER TABLE pharmacy_memberships ADD CONSTRAINT pharmacy_memberships_status_check CHECK (status IN ('active', 'suspended'))");
        DB::statement("ALTER TABLE pharmacy_memberships ADD CONSTRAINT pharmacy_memberships_credential_status_check CHECK (credential_status IN ('unverified', 'verified', 'rejected', 'expired'))");
        DB::statement(<<<'SQL'
ALTER TABLE pharmacy_memberships ADD CONSTRAINT pharmacy_memberships_credential_verification_check CHECK ((
    credential_status IS DISTINCT FROM 'verified'
    OR (
        staff_role = 'pharmacist'
        AND status = 'active'
        AND license_number IS NOT NULL
        AND btrim(license_number) <> ''
        AND credential_reviewed_by IS NOT NULL
        AND credential_reviewed_at IS NOT NULL
        AND credential_verification_method = 'documented_admin_review'
        AND credential_evidence IS NOT NULL
        AND btrim(credential_evidence) <> ''
    )
) IS TRUE)
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.medifind_clear_credential_review()
RETURNS trigger
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF NEW.staff_role IS DISTINCT FROM OLD.staff_role
        OR NEW.license_number IS DISTINCT FROM OLD.license_number THEN
        NEW.credential_status := 'unverified';
        NEW.credential_reviewed_by := NULL;
        NEW.credential_reviewed_at := NULL;
        NEW.credential_verification_method := NULL;
        NEW.credential_evidence := NULL;
    END IF;

    RETURN NEW;
END;
$fn$;

CREATE TRIGGER medifind_clear_credential_review
BEFORE UPDATE ON pharmacy_memberships
FOR EACH ROW
EXECUTE FUNCTION public.medifind_clear_credential_review();
SQL);

        Schema::create('account_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('email', 254);
            $table->string('kind', 32);
            $table->foreignUuid('pharmacy_id')->nullable()->constrained('pharmacies')->restrictOnDelete();
            $table->char('token_hash', 64);
            $table->string('delivery_status', 32)->default('pending');
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('superseded_at')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique('token_hash');
        });

        DB::statement('ALTER TABLE account_invitations ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE account_invitations ADD CONSTRAINT account_invitations_kind_check CHECK (kind IN ('admin', 'manager', 'pharmacist', 'inventory_staff'))");
        DB::statement("ALTER TABLE account_invitations ADD CONSTRAINT account_invitations_delivery_status_check CHECK (delivery_status IN ('pending', 'delivered'))");
        DB::statement(<<<'SQL'
ALTER TABLE account_invitations ADD CONSTRAINT account_invitations_scope_check CHECK ((
    (kind = 'admin' AND pharmacy_id IS NULL)
    OR (kind IN ('manager', 'pharmacist', 'inventory_staff') AND pharmacy_id IS NOT NULL)
) IS TRUE)
SQL);
        DB::statement("CREATE UNIQUE INDEX account_invitations_open_email_unique ON account_invitations (lower(email), kind, COALESCE(pharmacy_id::text, '')) WHERE accepted_at IS NULL AND revoked_at IS NULL AND superseded_at IS NULL");

        Schema::create('invitation_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_invitation_id')->unique()->constrained('account_invitations')->cascadeOnDelete();
            $table->string('purpose', 32);
            $table->text('token_ciphertext');
            $table->char('token_hash', 64);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->uuid('claim_id')->nullable()->unique();
            $table->timestampTz('claimed_at')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE invitation_deliveries ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE invitation_deliveries ADD CONSTRAINT invitation_deliveries_purpose_check CHECK (purpose IN ('initial', 'reissue'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_deliveries');
        Schema::dropIfExists('account_invitations');
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS medifind_clear_credential_review ON pharmacy_memberships;
DROP FUNCTION IF EXISTS public.medifind_clear_credential_review();
SQL);
        Schema::dropIfExists('pharmacy_memberships');
    }
};
