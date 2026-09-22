<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable();
            $table->string('phone_e164', 20)->nullable();
            $table->timestampTz('phone_verified_at')->nullable();
            $table->string('full_name', 120)->nullable();
            $table->string('role', 32)->default('customer');
            $table->string('status', 32)->default('active');
            $table->timestampTz('last_login_at')->nullable();
            $table->softDeletesTz();
        });

        DB::table('users')->whereNull('public_id')->update([
            'public_id' => DB::raw('gen_random_uuid()'),
        ]);

        DB::table('users')->whereNull('full_name')->whereNotNull('name')->update([
            'full_name' => DB::raw('name'),
        ]);

        DB::statement('ALTER TABLE users ALTER COLUMN public_id SET NOT NULL');
        DB::statement('ALTER TABLE users ALTER COLUMN public_id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE users ALTER COLUMN name DROP NOT NULL');
        DB::statement('ALTER TABLE users ALTER COLUMN email DROP NOT NULL');
        DB::statement('ALTER TABLE users ALTER COLUMN password DROP NOT NULL');

        Schema::table('users', function (Blueprint $table): void {
            $table->unique('public_id');
            $table->dropUnique(['email']);
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('customer', 'pharmacy_staff', 'admin'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active', 'suspended'))");
        DB::statement('CREATE UNIQUE INDEX users_phone_e164_active_unique ON users (phone_e164) WHERE deleted_at IS NULL AND phone_e164 IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX users_email_active_unique ON users (lower(email)) WHERE deleted_at IS NULL AND email IS NOT NULL');
    }

    public function down(): void
    {
        $incomplete = DB::table('users')->whereNull('email')->orWhereNull('password')->count();

        if ($incomplete > 0) {
            throw new RuntimeException('Cannot restore NOT NULL email and password while OTP users exist.');
        }

        DB::statement('DROP INDEX IF EXISTS users_email_active_unique');
        DB::statement('DROP INDEX IF EXISTS users_phone_e164_active_unique');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_status_check');

        DB::statement("UPDATE users SET name = COALESCE(NULLIF(btrim(name), ''), NULLIF(btrim(full_name), ''), 'Incomplete profile')");

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['public_id']);
        });

        DB::statement('ALTER TABLE users ALTER COLUMN name SET NOT NULL');
        DB::statement('ALTER TABLE users ALTER COLUMN email SET NOT NULL');
        DB::statement('ALTER TABLE users ALTER COLUMN password SET NOT NULL');

        Schema::table('users', function (Blueprint $table): void {
            $table->unique('email');
            $table->dropColumn([
                'public_id',
                'phone_e164',
                'phone_verified_at',
                'full_name',
                'role',
                'status',
                'last_login_at',
                'deleted_at',
            ]);
        });
    }
};
