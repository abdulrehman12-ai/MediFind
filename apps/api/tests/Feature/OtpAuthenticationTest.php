<?php

namespace Tests\Feature;

use App\Identity\Otp\InMemoryOtpProvider;
use App\Identity\Otp\OtpHasher;
use App\Identity\Otp\OtpKeys;
use App\Identity\Otp\OtpRedis;
use App\Identity\Otp\SmsOtpProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class OtpAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '03001234567';

    protected function tearDown(): void
    {
        app(OtpRedis::class)->deleteNamespace();

        parent::tearDown();
    }

    public function test_request_returns_challenge_without_the_code_or_account_state(): void
    {
        $response = $this->postJson('/api/v1/auth/otp/request', [
            'phone_number' => self::PHONE,
        ]);

        $response->assertStatus(202)
            ->assertJsonStructure(['data' => ['challenge_id', 'expires_in', 'retry_after'], 'meta' => ['request_id']])
            ->assertJsonPath('data.expires_in', 300)
            ->assertJsonPath('data.retry_after', 60)
            ->assertJsonCount(3, 'data')
            ->assertJsonMissingPath('data.otp');

        $challengeId = (string) $response->json('data.challenge_id');
        $code = app(InMemoryOtpProvider::class)->codeFor($challengeId);
        $this->assertNotNull($code);
        $this->assertStringNotContainsString((string) $code, (string) $response->getContent());

        $stored = app(OtpRedis::class)->challenge($challengeId);
        $this->assertIsArray($stored);
        $this->assertNotContains($code, $stored);
        $this->assertSame(
            app(OtpHasher::class)->otp($challengeId, '+923001234567', (string) $code),
            $stored['otp_hmac'] ?? null,
        );
    }

    public function test_request_is_generic_for_unknown_and_existing_phones(): void
    {
        $first = $this->postJson('/api/v1/auth/otp/request', ['phone_number' => self::PHONE]);
        app(OtpRedis::class)->clearCooldown(app(OtpHasher::class)->phone('+923001234567'));
        $this->verifyCode($first);
        app(OtpRedis::class)->clearCooldown(app(OtpHasher::class)->phone('+923001234567'));
        $second = $this->postJson('/api/v1/auth/otp/request', ['phone_number' => self::PHONE]);

        $first->assertStatus(202);
        $second->assertStatus(202);
        $this->assertSame(array_keys($first->json('data')), array_keys($second->json('data')));
    }

    public function test_verify_issues_a_token_once_and_creates_a_customer_without_fabricated_profile(): void
    {
        $requested = $this->postJson('/api/v1/auth/otp/request', ['phone_number' => self::PHONE]);
        $verified = $this->verifyCode($requested);

        $verified->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.customer.phone_masked', '+92300***4567')
            ->assertJsonMissingPath('data.customer.phone_number')
            ->assertJsonMissingPath('data.customer.phone_e164')
            ->assertJsonPath('data.customer.full_name', null)
            ->assertJsonPath('data.customer.email', null)
            ->assertJsonPath('data.customer.role', 'customer');

        $token = (string) $verified->json('data.access_token');
        $this->assertNotSame('', $token);
        $user = User::query()->where('phone_e164', '+923001234567')->firstOrFail();
        $this->assertNull($user->password);
        $this->assertNull($user->email);
        $this->assertNull($user->full_name);
        $this->assertNotSame($token, (string) $user->tokens()->value('token'));

        $profile = $this->withCustomerToken($token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.phone_masked', '+92300***4567')
            ->assertJsonMissingPath('data.access_token')
            ->assertJsonMissingPath('data.phone_number');
        $this->assertStringNotContainsString('+923001234567', (string) $profile->getContent());
        $this->assertStringNotContainsString('+923001234567', (string) $verified->getContent());
    }

    public function test_wrong_expired_replayed_and_exhausted_codes_are_rejected(): void
    {
        $redis = app(OtpRedis::class);
        $requested = $this->postJson('/api/v1/auth/otp/request', ['phone_number' => self::PHONE]);
        $challengeId = (string) $requested->json('data.challenge_id');
        $realCode = (string) app(InMemoryOtpProvider::class)->codeFor($challengeId);
        $wrong = $realCode === '000000' ? '111111' : '000000';

        $this->postJson('/api/v1/auth/otp/verify', [
            'phone_number' => self::PHONE,
            'challenge_id' => $challengeId,
            'otp' => $wrong,
        ])->assertStatus(422)->assertJsonPath('error.code', 'otp_invalid');

        $redis->deleteChallenge($challengeId, app(OtpHasher::class)->phone('+923001234567'));
        $requested = $this->postJson('/api/v1/auth/otp/request', ['phone_number' => '03011234567']);
        $challengeId = (string) $requested->json('data.challenge_id');
        $redis->forceExpired($challengeId);
        $this->postJson('/api/v1/auth/otp/verify', [
            'phone_number' => '03011234567',
            'challenge_id' => $challengeId,
            'otp' => (string) app(InMemoryOtpProvider::class)->codeFor($challengeId),
        ])->assertStatus(422)->assertJsonPath('error.code', 'otp_invalid');
        $this->assertFalse($redis->exists(OtpKeys::challenge($challengeId)));

        $requested = $this->postJson('/api/v1/auth/otp/request', ['phone_number' => '03021234567']);
        $verified = $this->verifyCode($requested, '03021234567');
        $verified->assertOk();
        $this->postJson('/api/v1/auth/otp/verify', [
            'phone_number' => '03021234567',
            'challenge_id' => (string) $requested->json('data.challenge_id'),
            'otp' => (string) app(InMemoryOtpProvider::class)->codeFor((string) $requested->json('data.challenge_id')),
        ])->assertStatus(422)->assertJsonPath('error.code', 'otp_invalid');

        $requested = $this->postJson('/api/v1/auth/otp/request', ['phone_number' => '03031234567']);
        $challengeId = (string) $requested->json('data.challenge_id');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/otp/verify', [
                'phone_number' => '03031234567',
                'challenge_id' => $challengeId,
                'otp' => '111111',
            ])->assertStatus(422);
        }
        $this->assertFalse($redis->exists(OtpKeys::challenge($challengeId)));
    }

    public function test_rate_limits_and_cooldown_consume_attempts_without_sleep(): void
    {
        $hasher = app(OtpHasher::class);
        $redis = app(OtpRedis::class);

        $this->postJson('/api/v1/auth/otp/request', ['phone_number' => self::PHONE])->assertStatus(202);
        $cooldown = $this->postJson('/api/v1/auth/otp/request', ['phone_number' => self::PHONE]);
        $cooldown->assertStatus(429)
            ->assertJsonPath('error.code', 'otp_cooldown')
            ->assertHeader('Retry-After');
        $this->assertSame(2, $redis->zcard(OtpKeys::attempt($hasher->ip('127.0.0.1'))));

        $phoneHmac = $hasher->phone('+923001234567');
        for ($send = 1; $send < 5; $send++) {
            $redis->clearCooldown($phoneHmac);
            $this->postJson('/api/v1/auth/otp/request', ['phone_number' => self::PHONE])->assertStatus(202);
        }
        $redis->clearCooldown($phoneHmac);
        $this->postJson('/api/v1/auth/otp/request', ['phone_number' => self::PHONE])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'otp_rate_limited');
        $this->assertSame(5, $redis->zcard(OtpKeys::send($phoneHmac)));
    }

    public function test_same_millisecond_event_ids_count_as_distinct_members(): void
    {
        $redis = app(OtpRedis::class);
        $key = OtpKeys::attempt('same-millisecond');
        $first = (string) Str::uuid();
        $second = (string) Str::uuid();

        $this->assertSame(2, $redis->evalSameScore($key, [$first, $second]));
        $this->assertEqualsCanonicalizing([$first, $second], $redis->members($key));

        $this->withServerVariables(['REMOTE_ADDR' => '10.8.7.6']);
        $this->postJson('/api/v1/auth/otp/request', ['phone_number' => '03041234567'])->assertStatus(202);
        $this->postJson('/api/v1/auth/otp/request', ['phone_number' => '03051234567'])->assertStatus(202);
        $members = $redis->members(OtpKeys::attempt(app(OtpHasher::class)->ip('10.8.7.6')));
        $this->assertCount(2, $members);
        $this->assertNotSame($members[0], $members[1]);
    }

    public function test_request_returns_unavailable_when_the_otp_store_cannot_connect(): void
    {
        $this->assertStoreOutageResponse(function (): TestResponse {
            return $this->postJson('/api/v1/auth/otp/request', ['phone_number' => '03061234901']);
        }, '03061234901', '+923061234901');
    }

    public function test_verification_returns_unavailable_when_the_otp_store_fails(): void
    {
        $requested = $this->postJson('/api/v1/auth/otp/request', ['phone_number' => '03061234902'])->assertStatus(202);
        $challengeId = (string) $requested->json('data.challenge_id');

        $this->assertStoreOutageResponse(function () use ($challengeId): TestResponse {
            return $this->postJson('/api/v1/auth/otp/verify', [
                'phone_number' => '03061234902',
                'challenge_id' => $challengeId,
                'otp' => '000000',
            ]);
        }, '03061234902', '+923061234902', [$challengeId, '000000']);
    }

    public function test_provider_failure_removes_only_the_challenge_and_hides_the_code(): void
    {
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });
        $this->app->instance(SmsOtpProvider::class, new class implements SmsOtpProvider
        {
            public function send(string $phoneE164, string $challengeId, string $code): void
            {
                throw new \RuntimeException('delivery failed');
            }
        });

        $response = $this->postJson('/api/v1/auth/otp/request', ['phone_number' => self::PHONE]);
        $response->assertStatus(503)->assertJsonPath('error.code', 'otp_delivery_unavailable');
        $this->assertStringNotContainsString('delivery failed', (string) $response->getContent());

        $phoneHmac = app(OtpHasher::class)->phone('+923001234567');
        $redis = app(OtpRedis::class);
        $this->assertSame(1, $redis->zcard(OtpKeys::send($phoneHmac)));
        $this->assertNull($redis->get(OtpKeys::active($phoneHmac)));
        $this->assertNotEmpty($logged);
        $this->assertSame('otp_delivery_unavailable', $logged[0]->message);
        $this->assertSame(['request_id'], array_keys($logged[0]->context));
    }

    public function test_ip_attempt_cap_bounds_the_sorted_set(): void
    {
        $redis = app(OtpRedis::class);
        $this->withServerVariables(['REMOTE_ADDR' => '10.4.4.4']);

        for ($index = 0; $index < 20; $index++) {
            $phone = '0301'.str_pad((string) (1234500 + $index), 7, '0', STR_PAD_LEFT);
            $this->postJson('/api/v1/auth/otp/request', ['phone_number' => $phone])->assertStatus(202);
        }

        $this->postJson('/api/v1/auth/otp/request', ['phone_number' => '03029999999'])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'otp_rate_limited');

        $this->assertSame(20, $redis->zcard(OtpKeys::attempt(app(OtpHasher::class)->ip('10.4.4.4'))));
    }

    public function test_successful_request_retry_after_follows_configuration(): void
    {
        config([
            'medifind.otp.cooldown_seconds' => 45,
            'medifind.otp.ttl_seconds' => 240,
        ]);

        $this->postJson('/api/v1/auth/otp/request', ['phone_number' => '03081234567'])
            ->assertStatus(202)
            ->assertJsonPath('data.expires_in', 240)
            ->assertJsonPath('data.retry_after', 45)
            ->assertJsonMissingPath('data.otp');
    }

    public function test_verification_failures_follow_configured_max_attempts(): void
    {
        config(['medifind.otp.max_attempts' => 2]);
        $requested = $this->postJson('/api/v1/auth/otp/request', ['phone_number' => '03091234567'])->assertStatus(202);
        $challengeId = (string) $requested->json('data.challenge_id');
        $payload = [
            'phone_number' => '03091234567',
            'challenge_id' => $challengeId,
            'otp' => '000000',
        ];

        $this->postJson('/api/v1/auth/otp/verify', $payload)->assertStatus(422);
        $this->assertNotNull(app(OtpRedis::class)->challenge($challengeId));
        $this->postJson('/api/v1/auth/otp/verify', $payload)->assertStatus(422);
        $this->assertNull(app(OtpRedis::class)->challenge($challengeId));
    }

    /**
     * @param  callable(): TestResponse  $send
     * @param  list<string>  $extraSecrets
     */
    private function assertStoreOutageResponse(callable $send, string $phone, string $e164, array $extraSecrets = []): void
    {
        $original = config('database.redis.default');
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });

        try {
            config([
                'database.redis.default.host' => '127.0.0.1',
                'database.redis.default.port' => 1,
                'database.redis.default.password' => null,
            ]);
            $this->app->forgetInstance(OtpRedis::class);

            $response = $send()->assertStatus(503)->assertJsonPath('error.code', 'otp_unavailable');
            $response->assertJsonPath('error.message', 'OTP is temporarily unavailable.');
            $response->assertJsonStructure([
                'error' => ['code', 'message', 'details'],
                'meta' => ['request_id'],
            ]);
            $this->assertNotSame('', (string) $response->json('meta.request_id'));

            $body = (string) $response->getContent();
            foreach (array_merge(['127.0.0.1', '6379', 'redis_test', 'Connection refused', 'RedisException', 'OTP_HMAC', 'password', $phone, $e164], $extraSecrets) as $secret) {
                $this->assertStringNotContainsString($secret, $body);
            }

            foreach ($logged as $event) {
                $encoded = json_encode(['message' => $event->message, 'context' => $event->context]);
                $this->assertIsString($encoded);
                foreach (array_merge(['127.0.0.1', '6379', 'redis_test', 'Connection refused', 'RedisException', $phone, $e164], $extraSecrets) as $secret) {
                    $this->assertStringNotContainsString($secret, $encoded);
                }
            }
        } finally {
            config(['database.redis.default' => $original]);
            $this->app->forgetInstance(OtpRedis::class);
        }
    }

    /**
     * @param  TestResponse  $requested
     */
    private function verifyCode($requested, string $phone = self::PHONE): TestResponse
    {
        $challengeId = (string) $requested->json('data.challenge_id');

        return $this->postJson('/api/v1/auth/otp/verify', [
            'phone_number' => $phone,
            'challenge_id' => $challengeId,
            'otp' => (string) app(InMemoryOtpProvider::class)->codeFor($challengeId),
        ]);
    }
}
