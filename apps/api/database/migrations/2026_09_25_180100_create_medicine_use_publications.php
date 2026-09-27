<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicine_use_publications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('medicine_id')->constrained('medicines')->restrictOnDelete();
            $table->foreignUuid('medicine_use_id')->constrained('medicine_uses')->restrictOnDelete();
            $table->string('locale', 10);
            $table->text('general_use');
            $table->text('safety_disclaimer');
            $table->text('source_citation');
            $table->foreignId('published_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('published_at');
            $table->timestamps();
            $table->unique(['medicine_id', 'locale']);
        });

        DB::statement('ALTER TABLE medicine_use_publications ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE medicine_use_publications ADD CONSTRAINT medicine_use_publications_text_check CHECK (length(btrim(general_use)) > 0 AND length(btrim(safety_disclaimer)) > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('medicine_use_publications');
    }
};
