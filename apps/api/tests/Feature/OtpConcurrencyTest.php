<?php

namespace Tests\Feature;

use App\Identity\Otp\InMemoryOtpProvider;
use App\Identity\Otp\OtpRedis;
use App\Models\User;
use App\Models\UserAddress;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OtpConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);
    }

    protected function tearDown(): void
    {
        DB::table('personal_access_tokens')->delete();
        DB::table('user_addresses')->delete();
        DB::table('users')->delete();
        app(OtpRedis::class)->deleteNamespace();

        parent::tearDown();
    }

    public function test_two_concurrent_verifications_issue_one_session(): void
    {
        $requested = $this->postJson('/api/v1/auth/otp/request', ['phone_number' => '03001234567'])->assertStatus(202);
        $challengeId = (string) $requested->json('data.challenge_id');
        $otp = (string) app(InMemoryOtpProvider::class)->codeFor($challengeId);
        $body = [
            'phone_number' => '03001234567',
            'challenge_id' => $challengeId,
            'otp' => $otp,
        ];

        $results = $this->parallel([
            ['uri' => '/api/v1/auth/otp/verify', 'method' => 'POST', 'body' => $body],
            ['uri' => '/api/v1/auth/otp/verify', 'method' => 'POST', 'body' => $body],
        ]);

        $successes = array_values(array_filter($results, fn (array $result): bool => $result['status'] === 200));
        $this->assertCount(1, $successes);
        $this->assertSame(1, User::query()->where('phone_e164', '+923001234567')->count());
        $this->assertSame(1, DB::table('personal_access_tokens')->count());
    }

    public function test_concurrent_default_switches_leave_one_default(): void
    {
        $token = $this->login('03001234567');
        $userId = User::query()->where('phone_e164', '+923001234567')->value('id');
        $payload = [
            'uri' => '/api/v1/me/addresses',
            'method' => 'POST',
            'authorization' => 'Bearer '.$token,
            'body' => [
                'label' => 'Home',
                'address_text' => 'Satiana Road, Faisalabad',
                'latitude' => 31.418,
                'longitude' => 73.079,
                'is_default' => true,
            ],
        ];
        $second = $payload;
        $second['body']['label'] = 'Work';

        $results = $this->parallel([$payload, $second]);

        foreach ($results as $result) {
            $this->assertSame(201, $result['status'], (string) ($result['code'] ?? ''));
        }

        $this->assertSame(1, UserAddress::query()->where('user_id', $userId)->where('is_default', true)->count());
    }

    private function login(string $phone): string
    {
        $requested = $this->postJson('/api/v1/auth/otp/request', ['phone_number' => $phone])->assertStatus(202);
        $challengeId = (string) $requested->json('data.challenge_id');

        return (string) $this->postJson('/api/v1/auth/otp/verify', [
            'phone_number' => $phone,
            'challenge_id' => $challengeId,
            'otp' => (string) app(InMemoryOtpProvider::class)->codeFor($challengeId),
        ])->assertOk()->json('data.access_token');
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     * @return list<array{status: int, code: ?string}>
     */
    private function parallel(array $payloads): array
    {
        $processes = [];
        $results = [];

        foreach ($payloads as $index => $payload) {
            $input = tempnam(sys_get_temp_dir(), 'mf-in');
            $output = tempnam(sys_get_temp_dir(), 'mf-out');
            file_put_contents((string) $input, json_encode($payload));
            $process = proc_open([PHP_BINARY, base_path('tests/Support/http-worker.php'), (string) $input, (string) $output], [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, base_path());
            $this->assertIsResource($process);
            fclose($pipes[0]);
            $processes[] = [$process, $pipes, $input, $output];
            $results[$index] = $output;
        }

        $decoded = [];

        foreach ($processes as $index => [$process, $pipes, $input, $output]) {
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $raw = json_decode((string) file_get_contents((string) $output), true);
            $decoded[] = [
                'status' => (int) ($raw['status'] ?? 0),
                'code' => isset($raw['code']) && is_string($raw['code']) ? $raw['code'] : null,
            ];
            @unlink((string) $input);
            @unlink((string) $output);
        }

        return $decoded;
    }
}
