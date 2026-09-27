<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class PrescriptionTimestampRollbackTest extends TestCase
{
    private const MIGRATION = '2026_09_27_140000_prescription_timestamps_keep_microseconds';

    protected function tearDown(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true, '--seed' => true]);

        parent::tearDown();
    }

    public function test_standalone_rollback_keeps_fractional_timestamps_and_allows_whole_seconds(): void
    {
        $migration = require database_path('migrations/'.self::MIGRATION.'.php');
        $connection = DB::connection();
        $connection->getSchemaBuilder();
        $this->assertTrue($migration->withinTransaction);
        $this->assertTrue($connection->getSchemaGrammar()->supportsSchemaTransactions());

        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
        $this->assertSame(self::MIGRATION, DB::table('migrations')->orderByDesc('id')->value('migration'));

        $userId = DB::table('users')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'email' => 'rx-timestamp-rollback@example.test',
            'role' => 'customer',
            'status' => 'active',
            'created_at' => '2026-03-15 08:00:00',
            'updated_at' => '2026-03-15 08:00:00',
        ]);
        $prescriptionId = (string) Str::uuid();
        DB::table('prescriptions')->insert([
            'id' => $prescriptionId,
            'public_id' => (string) Str::uuid(),
            'customer_id' => $userId,
            'object_key' => 'rx/'.Str::uuid()->toString(),
            'display_name' => 'prescription',
            'mime_type' => 'image/jpeg',
            'byte_size' => 128,
            'sha256' => hash('sha256', 'fraction'),
            'extraction_state' => 'queued',
            'extraction_version' => 1,
            'content_version' => 1,
            'share_state' => 'none',
            'object_state' => 'stored',
            'created_at' => '2026-03-15 08:00:00.000003',
            'updated_at' => '2026-03-15 08:00:00',
        ]);

        $created = $this->timestamp($prescriptionId, 'created_at');
        $updated = $this->timestamp($prescriptionId, 'updated_at');
        $this->assertMatchesRegularExpression('/\.000003$/', $created);
        $this->assertRollbackRefused();
        $this->assertSame($created, $this->timestamp($prescriptionId, 'created_at'));
        $this->assertSame($updated, $this->timestamp($prescriptionId, 'updated_at'));
        $this->assertSame(6, $this->precision('created_at'));
        $this->assertSame(6, $this->precision('updated_at'));
        $this->assertTrue(DB::table('migrations')->where('migration', self::MIGRATION)->exists());

        DB::table('prescriptions')->where('id', $prescriptionId)->update([
            'created_at' => '2026-03-15 08:00:00',
            'updated_at' => '2026-03-15 08:00:01.000004',
        ]);
        $created = $this->timestamp($prescriptionId, 'created_at');
        $updated = $this->timestamp($prescriptionId, 'updated_at');
        $this->assertMatchesRegularExpression('/\.000004$/', $updated);
        $this->assertRollbackRefused();
        $this->assertSame($created, $this->timestamp($prescriptionId, 'created_at'));
        $this->assertSame($updated, $this->timestamp($prescriptionId, 'updated_at'));
        $this->assertSame(6, $this->precision('created_at'));
        $this->assertSame(6, $this->precision('updated_at'));

        DB::table('prescriptions')->where('id', $prescriptionId)->update([
            'created_at' => '2026-03-15 08:00:02',
            'updated_at' => '2026-03-15 08:00:03',
        ]);
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--force' => true, '--step' => 1]));
        $this->assertFalse(DB::table('migrations')->where('migration', self::MIGRATION)->exists());
        $this->assertSame(0, $this->precision('created_at'));
        $this->assertSame(0, $this->precision('updated_at'));
        $this->assertSame('2026-03-15 08:00:02', $this->timestamp($prescriptionId, 'created_at'));
        $this->assertSame('2026-03-15 08:00:03', $this->timestamp($prescriptionId, 'updated_at'));
    }

    private function assertRollbackRefused(): void
    {
        try {
            Artisan::call('migrate:rollback', ['--force' => true, '--step' => 1]);
            $this->fail('A standalone rollback rounded fractional prescription timestamps.');
        } catch (Throwable $exception) {
            $this->assertInstanceOf(RuntimeException::class, $exception);
            $this->assertSame(
                'Prescription timestamp precision cannot be rolled back while created_at or updated_at contains fractional seconds.',
                $exception->getMessage(),
            );
        }
    }

    private function timestamp(string $prescriptionId, string $column): string
    {
        return (string) DB::table('prescriptions')->where('id', $prescriptionId)->value($column);
    }

    private function precision(string $column): int
    {
        return (int) DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', 'prescriptions')
            ->where('column_name', $column)
            ->value('datetime_precision');
    }
}
