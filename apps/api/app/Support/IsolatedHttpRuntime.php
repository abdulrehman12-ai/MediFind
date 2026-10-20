<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

final class IsolatedHttpRuntime
{
    public static function assert(): void
    {
        TestingSafety::assertSafeForDestructiveDatabaseWork();
        TestingSafety::assertSafeForTestRedisCleanup();
        self::assertPrescriptionTestingDisk();
        self::assertLocalOtpProvider();
    }

    public static function assertPrescriptionTestingDisk(): void
    {
        $configured = (string) config('medifind.prescriptions.disk');

        if ($configured !== 'prescriptions_testing') {
            throw new RuntimeException('Isolated HTTP runtime requires PRESCRIPTION_DISK=prescriptions_testing.');
        }

        $driver = (string) config('filesystems.disks.prescriptions_testing.driver');

        if ($driver !== 'local') {
            throw new RuntimeException('prescriptions_testing must use the local driver.');
        }
    }

    public static function assertLocalOtpProvider(): void
    {
        $provider = (string) config('medifind.otp.provider');

        if ($provider !== 'local') {
            throw new RuntimeException('Isolated HTTP runtime requires SMS_OTP_PROVIDER=local.');
        }

        $code = (string) config('medifind.otp.local_code');

        if (preg_match('/^\d{6}$/', $code) !== 1) {
            throw new RuntimeException('Isolated HTTP runtime requires a six-digit LOCAL_OTP_CODE.');
        }
    }

    public static function assertRedisReachable(): void
    {
        try {
            Redis::connection()->ping();
        } catch (Throwable $e) {
            throw new RuntimeException('Isolated HTTP runtime cannot reach redis_test.', 0, $e);
        }
    }
}
