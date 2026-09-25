<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('generic_name', 180);
            $table->string('brand_name', 180)->nullable();
            $table->string('strength', 80)->nullable();
            $table->string('dosage_form', 80);
            $table->string('manufacturer', 180)->nullable();
            $table->string('normalized_generic_name', 180);
            $table->string('normalized_brand_name', 180)->default('');
            $table->string('normalized_strength', 80)->default('');
            $table->string('normalized_dosage_form', 80);
            $table->string('normalized_manufacturer', 180)->default('');
            $table->boolean('prescription_required')->default(false);
            $table->boolean('controlled_drug')->default(false);
            $table->string('status', 32)->default('inactive');
            $table->timestamps();
        });

        DB::statement('ALTER TABLE medicines ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE medicines ADD CONSTRAINT medicines_status_check CHECK (status IN ('inactive', 'active'))");
        DB::statement(<<<'SQL'
ALTER TABLE medicines ADD CONSTRAINT medicines_active_identity_check CHECK (
    status <> 'active'
    OR (
        length(btrim(normalized_generic_name)) > 0
        AND length(btrim(normalized_strength)) > 0
        AND length(btrim(normalized_dosage_form)) > 0
        AND length(btrim(normalized_manufacturer)) > 0
    )
)
SQL);
        DB::statement(<<<'SQL'
CREATE UNIQUE INDEX medicines_identity_unique
ON medicines (
    normalized_generic_name,
    normalized_brand_name,
    normalized_strength,
    normalized_dosage_form,
    normalized_manufacturer
)
WHERE normalized_manufacturer <> ''
SQL);

        Schema::create('medicine_aliases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('medicine_id')->constrained('medicines')->restrictOnDelete();
            $table->string('alias', 180);
            $table->string('alias_type', 32);
            $table->string('normalized_alias', 180);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['medicine_id', 'normalized_alias']);
        });

        DB::statement('ALTER TABLE medicine_aliases ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE medicine_aliases ADD CONSTRAINT medicine_aliases_type_check CHECK (alias_type IN ('brand', 'generic', 'spelling', 'local'))");
        DB::statement('CREATE INDEX medicine_aliases_normalized_alias_index ON medicine_aliases (normalized_alias)');

        Schema::create('medicine_uses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('medicine_id')->constrained('medicines')->restrictOnDelete();
            $table->string('locale', 10)->default('en-PK');
            $table->text('general_use');
            $table->text('safety_disclaimer');
            $table->text('source_citation');
            $table->string('review_status', 32)->default('draft');
            $table->foreignId('drafted_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['medicine_id', 'locale']);
        });

        DB::statement('ALTER TABLE medicine_uses ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE medicine_uses ADD CONSTRAINT medicine_uses_review_status_check CHECK (review_status = 'draft')");
    }

    public function down(): void
    {
        Schema::dropIfExists('medicine_uses');
        Schema::dropIfExists('medicine_aliases');
        Schema::dropIfExists('medicines');
    }
};
