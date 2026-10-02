<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Throwable;

#[Group('phase6-upgrade')]
class Phase6UpgradeTest extends TestCase
{
    private const PHASE6 = 'database/migrations/2026_09_30_120000_phase6_pharmacy_fulfillment.php';

    private const PHASE6_CASH = 'database/migrations/2026_10_02_120000_phase6_collected_cash_present.php';

    protected function tearDown(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true, '--seed' => true]);
        parent::tearDown();
    }

    public function test_phase_6_upgrades_existing_orders_and_refuses_to_erase_fulfillment_records(): void
    {
        $this->assertSame(0, Artisan::call('db:wipe', ['--force' => true, '--database' => 'pgsql']));
        $paths = glob(database_path('migrations/*.php')) ?: [];
        sort($paths);

        foreach ($paths as $path) {
            if (str_ends_with($path, '2026_09_30_120000_phase6_pharmacy_fulfillment.php')
                || str_ends_with($path, '2026_10_02_120000_phase6_collected_cash_present.php')) {
                continue;
            }

            $this->assertSame(0, Artisan::call('migrate', [
                '--force' => true,
                '--path' => 'database/migrations/'.basename($path),
            ]));
        }

        $this->assertTrue(Schema::hasTable('orders'));
        $this->assertFalse(Schema::hasTable('order_quotes'));
        $userId = DB::table('users')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'email' => 'phase6-upgrade@example.test',
            'role' => 'customer',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cityId = (string) Str::uuid();
        DB::table('cities')->insert([
            'id' => $cityId,
            'name' => 'Phase6 Upgrade',
            'country_code' => 'PK',
            'timezone' => 'Asia/Karachi',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $pharmacyId = (string) Str::uuid();
        DB::table('pharmacies')->insert([
            'id' => $pharmacyId,
            'city_id' => $cityId,
            'name' => 'Upgrade Pharmacy',
            'address_text' => 'Synthetic road',
            'status' => 'pending',
            'license_status' => 'unverified',
            'location_review_status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderId = (string) Str::uuid();
        DB::table('orders')->insert([
            'id' => $orderId,
            'public_id' => (string) Str::uuid(),
            'reference' => 'MFPHASE6',
            'customer_id' => $userId,
            'pharmacy_id' => $pharmacyId,
            'request_mode' => 'prescription_full_image',
            'status' => 'pending_pharmacy_review',
            'payment_method' => 'cash_on_pickup',
            'payment_status' => 'unpaid',
            'estimate_total_paisa' => null,
            'prescription_usable' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--path' => self::PHASE6]));
        $this->assertTrue(Schema::hasTable('order_quotes'));
        $this->assertTrue(Schema::hasTable('order_reservations'));
        $this->assertFalse($this->hasCollectedCashConstraint());
        $this->assertSame('pending_pharmacy_review', DB::table('orders')->where('id', $orderId)->value('status'));
        $this->assertSame('unpaid', DB::table('orders')->where('id', $orderId)->value('payment_status'));
        $this->assertNull(DB::table('orders')->where('id', $orderId)->value('confirmed_total_paisa'));

        $invalidId = (string) Str::uuid();
        $windowStart = now();
        $windowEnd = now()->addHour();
        DB::table('orders')->insert([
            'id' => $invalidId,
            'public_id' => (string) Str::uuid(),
            'reference' => 'MFPHASE6B',
            'customer_id' => $userId,
            'pharmacy_id' => $pharmacyId,
            'request_mode' => 'prescription_full_image',
            'status' => 'collected',
            'payment_method' => 'cash_on_pickup',
            'payment_status' => 'paid',
            'estimate_total_paisa' => null,
            'confirmed_total_paisa' => 2500,
            'cash_collected_paisa' => null,
            'pickup_window_starts_at' => $windowStart,
            'pickup_window_ends_at' => $windowEnd,
            'pickup_grace_seconds' => 7200,
            'collect_until' => $windowEnd->copy()->addSeconds(7200),
            'prescription_usable' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cashFailed = false;

        try {
            Artisan::call('migrate', ['--force' => true, '--path' => self::PHASE6_CASH]);
        } catch (Throwable $exception) {
            $cashFailed = str_contains($exception->getMessage(), 'orders_collected_cash_present_check')
                || str_contains($exception->getMessage(), 'check constraint');
        }

        $this->assertTrue($cashFailed);
        $this->assertSame(1, DB::table('orders')->where('id', $invalidId)->count());
        $this->assertNull(DB::table('orders')->where('id', $invalidId)->value('cash_collected_paisa'));
        $this->assertFalse($this->hasCollectedCashConstraint());

        DB::table('orders')->where('id', $invalidId)->delete();
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--path' => self::PHASE6_CASH]));
        $this->assertTrue($this->hasCollectedCashConstraint());
        $this->assertSame(1, DB::table('orders')->where('id', $orderId)->count());

        $this->assertSame(0, Artisan::call('migrate:rollback', ['--force' => true, '--step' => 1]));
        $this->assertFalse($this->hasCollectedCashConstraint());
        $this->assertTrue(Schema::hasTable('order_quotes'));
        $this->assertTrue(Schema::hasTable('orders'));
        $this->assertSame(1, DB::table('orders')->where('id', $orderId)->count());

        $this->assertSame(0, Artisan::call('migrate:rollback', ['--force' => true, '--step' => 1]));
        $this->assertFalse(Schema::hasTable('order_quotes'));
        $this->assertFalse(Schema::hasTable('order_reservations'));
        $this->assertTrue(Schema::hasTable('orders'));
        $this->assertSame(1, DB::table('orders')->where('id', $orderId)->count());
        $this->assertSame('phase6-upgrade@example.test', DB::table('users')->where('id', $userId)->value('email'));

        $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--path' => self::PHASE6]));
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--path' => self::PHASE6_CASH]));
        DB::table('order_quotes')->insert([
            'id' => (string) Str::uuid(),
            'order_id' => $orderId,
            'version' => 1,
            'state' => 'open',
            'total_paisa' => 0,
            'created_by' => $userId,
            'expires_at' => now()->addDay(),
            'created_at' => now(),
        ]);

        $this->assertSame(0, Artisan::call('migrate:rollback', ['--force' => true, '--step' => 1]));
        $this->assertFalse($this->hasCollectedCashConstraint());
        $this->assertSame(1, DB::table('order_quotes')->count());
        $this->assertSame(1, DB::table('orders')->where('id', $orderId)->count());

        $refused = false;

        try {
            $code = Artisan::call('migrate:rollback', ['--force' => true, '--step' => 1]);
            $refused = $code !== 0;
        } catch (Throwable $exception) {
            $refused = str_contains($exception->getMessage(), 'Cannot roll back pharmacy fulfillment');
        }

        $this->assertTrue($refused);
        $this->assertSame(1, DB::table('order_quotes')->count());
        $this->assertSame(1, DB::table('orders')->where('id', $orderId)->count());
    }

    private function hasCollectedCashConstraint(): bool
    {
        return DB::selectOne(
            "select 1 as present from pg_constraint where conname = 'orders_collected_cash_present_check'",
        ) !== null;
    }
}
