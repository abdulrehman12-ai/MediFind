<?php

namespace Tests\Unit;

use App\Support\IsolatedHttpRuntime;
use RuntimeException;
use Tests\TestCase;

class IsolatedHttpRuntimeTest extends TestCase
{
    public function test_rejects_memory_otp_provider_used_by_phpunit(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Isolated HTTP runtime requires SMS_OTP_PROVIDER=local.');
        IsolatedHttpRuntime::assert();
    }

    public function test_rejects_wrong_prescription_disk(): void
    {
        config(['medifind.prescriptions.disk' => 'prescriptions']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Isolated HTTP runtime requires PRESCRIPTION_DISK=prescriptions_testing.');
        IsolatedHttpRuntime::assertPrescriptionTestingDisk();
    }

    public function test_accepts_local_otp_and_testing_disk_when_database_is_isolated(): void
    {
        config([
            'medifind.otp.provider' => 'local',
            'medifind.otp.local_code' => '246801',
            'medifind.prescriptions.disk' => 'prescriptions_testing',
            'filesystems.disks.prescriptions_testing.driver' => 'local',
        ]);

        IsolatedHttpRuntime::assert();
        $this->assertTrue(true);
    }
}
