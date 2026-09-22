<?php

namespace Tests\Feature;

use App\Identity\Otp\OtpHasher;
use App\Identity\Otp\OtpKeys;
use App\Identity\Otp\OtpRedis;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicOtpLimitTest extends TestCase
{
    protected function tearDown(): void
    {
        app(OtpRedis::class)->deleteNamespace();

        parent::tearDown();
    }

    public function test_coarse_limit_counts_invalid_request_payloads(): void
    {
        config(['medifind.otp.public_ip_limit' => 2]);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20']);

        $this->postJson('/api/v1/auth/otp/request', ['phone_number' => 'not-a-phone'])->assertStatus(422);
        $this->postJson('/api/v1/auth/otp/request', ['phone_number' => 'not-a-phone'])->assertStatus(422);
        $this->postJson('/api/v1/auth/otp/request', ['phone_number' => 'not-a-phone'])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'otp_rate_limited')
            ->assertHeader('Retry-After');

        $this->assertLimiterKeyOmitsRawIp('198.51.100.20');
    }

    public function test_coarse_limit_counts_unknown_verification_challenges(): void
    {
        config(['medifind.otp.public_ip_limit' => 2]);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.21']);
        $payload = [
            'phone_number' => '03001234567',
            'challenge_id' => (string) Str::uuid(),
            'otp' => '000000',
        ];

        $this->postJson('/api/v1/auth/otp/verify', $payload)->assertStatus(422);
        $this->postJson('/api/v1/auth/otp/verify', $payload)->assertStatus(422);
        $this->postJson('/api/v1/auth/otp/verify', $payload)
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'otp_rate_limited')
            ->assertHeader('Retry-After');

        $this->assertLimiterKeyOmitsRawIp('198.51.100.21');
    }

    private function assertLimiterKeyOmitsRawIp(string $ip): void
    {
        $expected = OtpKeys::publicBurst(app(OtpHasher::class)->ip($ip));
        $found = false;

        foreach (app(OtpRedis::class)->prefixedKeys() as $key) {
            $this->assertStringNotContainsString($ip, $key);

            if ($key === $expected) {
                $found = true;
            }
        }

        $this->assertTrue($found);
    }
}
