<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacy_operating_hours', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('pharmacy_id')->constrained('pharmacies')->restrictOnDelete();
            $table->unsignedSmallInteger('day_of_week');
            $table->string('interval_kind', 32);
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE pharmacy_operating_hours ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE pharmacy_operating_hours ADD CONSTRAINT pharmacy_hours_day_check CHECK (day_of_week BETWEEN 1 AND 7)');
        DB::statement(<<<'SQL'
ALTER TABLE pharmacy_operating_hours ADD CONSTRAINT pharmacy_hours_kind_check CHECK (
    (
        interval_kind = 'all_day'
        AND opens_at IS NULL
        AND closes_at IS NULL
    ) OR (
        interval_kind = 'same_day'
        AND opens_at IS NOT NULL
        AND closes_at IS NOT NULL
        AND opens_at < closes_at
    ) OR (
        interval_kind = 'overnight'
        AND opens_at IS NOT NULL
        AND closes_at IS NOT NULL
        AND opens_at > closes_at
    )
)
SQL);
        DB::statement('CREATE INDEX pharmacy_operating_hours_pharmacy_day_index ON pharmacy_operating_hours (pharmacy_id, day_of_week)');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.medifind_assert_pharmacy_hours()
RETURNS trigger
LANGUAGE plpgsql
AS $fn$
DECLARE
    target uuid;
    rec record;
    ranges int4range[] := ARRAY[]::int4range[];
    candidate int4range;
    existing int4range;
    day_start integer;
    open_seconds integer;
    close_seconds integer;
    next_start integer;
BEGIN
    target := COALESCE(NEW.pharmacy_id, OLD.pharmacy_id);

    PERFORM 1 FROM pharmacies WHERE id = target FOR UPDATE;

    FOR rec IN
        SELECT day_of_week, interval_kind, opens_at, closes_at
        FROM pharmacy_operating_hours
        WHERE pharmacy_id = target
    LOOP
        day_start := (rec.day_of_week - 1) * 86400;

        IF rec.interval_kind = 'all_day' THEN
            candidate := int4range(day_start, day_start + 86400, '[)');
            FOREACH existing IN ARRAY ranges LOOP
                IF candidate && existing THEN
                    RAISE EXCEPTION 'Opening intervals overlap';
                END IF;
            END LOOP;
            ranges := array_append(ranges, candidate);
        ELSE
            open_seconds := EXTRACT(EPOCH FROM rec.opens_at)::integer;
            close_seconds := EXTRACT(EPOCH FROM rec.closes_at)::integer;

            IF rec.interval_kind = 'same_day' THEN
                candidate := int4range(day_start + open_seconds, day_start + close_seconds, '[)');
                FOREACH existing IN ARRAY ranges LOOP
                    IF candidate && existing THEN
                        RAISE EXCEPTION 'Opening intervals overlap';
                    END IF;
                END LOOP;
                ranges := array_append(ranges, candidate);
            ELSE
                candidate := int4range(day_start + open_seconds, day_start + 86400, '[)');
                FOREACH existing IN ARRAY ranges LOOP
                    IF candidate && existing THEN
                        RAISE EXCEPTION 'Opening intervals overlap';
                    END IF;
                END LOOP;
                ranges := array_append(ranges, candidate);

                next_start := (rec.day_of_week % 7) * 86400;
                candidate := int4range(next_start, next_start + close_seconds, '[)');
                FOREACH existing IN ARRAY ranges LOOP
                    IF candidate && existing THEN
                        RAISE EXCEPTION 'Opening intervals overlap';
                    END IF;
                END LOOP;
                ranges := array_append(ranges, candidate);
            END IF;
        END IF;
    END LOOP;

    RETURN COALESCE(NEW, OLD);
END;
$fn$;

CREATE TRIGGER medifind_pharmacy_hours_overlap
AFTER INSERT OR UPDATE OR DELETE ON pharmacy_operating_hours
FOR EACH ROW
EXECUTE FUNCTION public.medifind_assert_pharmacy_hours();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS medifind_pharmacy_hours_overlap ON pharmacy_operating_hours;
DROP FUNCTION IF EXISTS public.medifind_assert_pharmacy_hours();
SQL);
        Schema::dropIfExists('pharmacy_operating_hours');
    }
};
