<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS medifind_user_token_revocation ON public.users;
CREATE OR REPLACE FUNCTION public.medifind_revoke_tokens_on_account_block()
RETURNS trigger
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF (NEW.status = 'suspended' AND OLD.status IS DISTINCT FROM 'suspended')
       OR (OLD.deleted_at IS NULL AND NEW.deleted_at IS NOT NULL)
       OR (OLD.role = 'customer' AND NEW.role IS DISTINCT FROM 'customer') THEN
        DELETE FROM public.personal_access_tokens
        WHERE tokenable_type = 'App\Models\User'
          AND tokenable_id = NEW.id;
    END IF;

    RETURN NEW;
END;
$fn$;

CREATE TRIGGER medifind_user_token_revocation
AFTER UPDATE OF status, deleted_at, role ON public.users
FOR EACH ROW
EXECUTE FUNCTION public.medifind_revoke_tokens_on_account_block();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS medifind_user_token_revocation ON public.users;
CREATE OR REPLACE FUNCTION public.medifind_revoke_tokens_on_account_block()
RETURNS trigger
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF (NEW.status = 'suspended' AND OLD.status IS DISTINCT FROM 'suspended')
       OR (OLD.deleted_at IS NULL AND NEW.deleted_at IS NOT NULL) THEN
        DELETE FROM public.personal_access_tokens
        WHERE tokenable_type = 'App\Models\User'
          AND tokenable_id = NEW.id;
    END IF;

    RETURN NEW;
END;
$fn$;

CREATE TRIGGER medifind_user_token_revocation
AFTER UPDATE OF status, deleted_at ON public.users
FOR EACH ROW
EXECUTE FUNCTION public.medifind_revoke_tokens_on_account_block();
SQL);
    }
};
