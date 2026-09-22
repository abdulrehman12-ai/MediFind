<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Identity\Otp\InMemoryOtpProvider;
use App\Identity\Otp\OtpRedis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityEventTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<MessageLogged>
     */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        Log::listen(function (MessageLogged $event): void {
            $this->logged[] = $event;
        });
    }

    protected function tearDown(): void
    {
        app(OtpRedis::class)->deleteNamespace();

        parent::tearDown();
    }

    public function test_security_events_keep_only_the_request_id(): void
    {
        $phone = '03005551212';
        $e164 = '+923005551212';
        $requested = $this->postJson('/api/v1/auth/otp/request', ['phone_number' => $phone])->assertStatus(202);
        $challengeId = (string) $requested->json('data.challenge_id');
        $code = (string) app(InMemoryOtpProvider::class)->codeFor($challengeId);
        $wrong = $code === '111111' ? '000000' : '111111';

        $this->postJson('/api/v1/auth/otp/verify', [
            'phone_number' => $phone,
            'challenge_id' => $challengeId,
            'otp' => $wrong,
        ])->assertStatus(422);

        $verified = $this->postJson('/api/v1/auth/otp/verify', [
            'phone_number' => $phone,
            'challenge_id' => $challengeId,
            'otp' => $code,
        ])->assertOk();
        $token = (string) $verified->json('data.access_token');

        $this->postJson('/api/v1/auth/otp/request', ['phone_number' => $phone])->assertStatus(429);

        $this->withCustomerToken($token)->post('/api/v1/auth/logout')->assertNoContent();

        User::query()->forceCreate([
            'public_id' => (string) Str::uuid(),
            'phone_e164' => '+923005551213',
            'role' => UserRole::Admin,
            'status' => AccountStatus::Active,
        ]);
        $adminRequest = $this->postJson('/api/v1/auth/otp/request', ['phone_number' => '03005551213'])->assertStatus(202);
        $adminChallenge = (string) $adminRequest->json('data.challenge_id');
        $this->postJson('/api/v1/auth/otp/verify', [
            'phone_number' => '03005551213',
            'challenge_id' => $adminChallenge,
            'otp' => (string) app(InMemoryOtpProvider::class)->codeFor($adminChallenge),
        ])->assertForbidden();

        $events = [
            'otp_requested',
            'otp_verify_failed',
            'otp_verify_succeeded',
            'otp_throttled',
            'token_revoked',
            'authorization_denied',
        ];
        $seen = [];

        foreach ($this->logged as $event) {
            if (! in_array($event->message, $events, true)) {
                continue;
            }

            $seen[] = $event->message;
            $this->assertSame(['request_id'], array_keys($event->context));
            $encoded = json_encode(['message' => $event->message, 'context' => $event->context]);
            $this->assertIsString($encoded);
            foreach ([$phone, $e164, '+92300***1212', $code, $challengeId, $token, 'LOCAL_OTP_CODE', 'OTP_HMAC_KEY'] as $secret) {
                $this->assertStringNotContainsString($secret, $encoded);
            }
        }

        foreach ($events as $event) {
            $this->assertContains($event, $seen);
        }
    }
}
