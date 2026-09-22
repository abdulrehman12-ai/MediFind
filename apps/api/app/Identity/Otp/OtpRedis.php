<?php

declare(strict_types=1);

namespace App\Identity\Otp;

use App\Support\TestingSafety;
use Redis;
use RedisException;
use RuntimeException;

final class OtpRedis
{
    private ?Redis $redis = null;

    private function client(): Redis
    {
        if ($this->redis instanceof Redis) {
            return $this->redis;
        }

        $config = config('database.redis.default');
        $host = (string) ($config['host'] ?? '');
        $port = (int) ($config['port'] ?? 6379);
        $redis = new Redis;

        try {
            $connected = $redis->connect($host, $port, 2.0);

            if ($connected !== true) {
                throw new OtpStoreUnavailable;
            }

            $password = $config['password'] ?? null;

            if (is_string($password) && $password !== '' && $redis->auth($password) !== true) {
                throw new OtpStoreUnavailable;
            }

            $database = (int) ($config['database'] ?? 0);

            if ($database !== 0 && $redis->select($database) !== true) {
                throw new OtpStoreUnavailable;
            }

            $redis->setOption(Redis::OPT_PREFIX, '');
        } catch (OtpStoreUnavailable $failure) {
            throw $failure;
        } catch (RedisException) {
            throw new OtpStoreUnavailable;
        }

        return $this->redis = $redis;
    }

    public function host(): string
    {
        $host = $this->call(fn (): mixed => $this->client()->getHost());

        return is_string($host) ? $host : '';
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function time(): array
    {
        $time = $this->call(fn (): mixed => $this->client()->time());

        if (! is_array($time)) {
            throw new OtpStoreUnavailable;
        }

        return [(int) ($time[0] ?? 0), (int) ($time[1] ?? 0)];
    }

    /**
     * @param  list<string>  $arguments
     * @return array<int, mixed>
     */
    public function request(array $arguments): array
    {
        $script = file_get_contents(__DIR__.'/otp_request.lua');

        if ($script === false) {
            throw new RuntimeException('OTP request script is missing.');
        }

        $result = $this->call(fn (): mixed => $this->client()->eval($script, $arguments, 0));

        if (! is_array($result)) {
            throw new OtpStoreUnavailable;
        }

        return $result;
    }

    /**
     * @return array<string, string>|null
     */
    public function challenge(string $challengeId): ?array
    {
        $value = $this->call(fn (): mixed => $this->client()->hGetAll(OtpKeys::challenge($challengeId)));

        if (! is_array($value) || $value === []) {
            return null;
        }

        $challenge = [];

        foreach ($value as $field => $fieldValue) {
            $challenge[(string) $field] = (string) $fieldValue;
        }

        return $challenge;
    }

    /**
     * @return array{allowed: bool, retry_after: int}
     */
    public function consumePublicAllowance(string $ipHmac, int $limit, int $windowSeconds): array
    {
        if ($limit < 1 || $windowSeconds < 1) {
            throw new RuntimeException('OTP public limit is invalid.');
        }

        $result = $this->call(fn (): mixed => $this->client()->eval(<<<'LUA'
local current = redis.call('INCR', KEYS[1])
if current == 1 then
    redis.call('EXPIRE', KEYS[1], tonumber(ARGV[1]))
end
local ttl = redis.call('TTL', KEYS[1])
if ttl < 1 then
    ttl = tonumber(ARGV[1])
end
if current > tonumber(ARGV[2]) then
    return {0, ttl}
end
return {1, ttl}
LUA, [OtpKeys::publicBurst($ipHmac), (string) $windowSeconds, (string) $limit], 1));

        $allowed = is_array($result) && (int) ($result[0] ?? 0) === 1;
        $retryAfter = is_array($result) ? (int) ($result[1] ?? $windowSeconds) : $windowSeconds;

        return [
            'allowed' => $allowed,
            'retry_after' => max(1, $retryAfter),
        ];
    }

    /**
     * @return list<string>
     */
    public function prefixedKeys(): array
    {
        $iterator = null;
        $keys = [];

        do {
            $batch = $this->call(function () use (&$iterator): mixed {
                return $this->client()->scan($iterator, OtpKeys::PREFIX.'*', 200);
            });

            if (! is_array($batch)) {
                break;
            }

            foreach ($batch as $key) {
                $keys[] = (string) $key;
            }
        } while ($iterator !== 0 && $iterator !== '0');

        return $keys;
    }

    public function acquireLock(string $challengeId, string $token): bool
    {
        $result = $this->call(fn (): mixed => $this->client()->set(OtpKeys::lock($challengeId), $token, ['NX', 'PX' => 5000]));

        return $result === true;
    }

    public function releaseLock(string $challengeId, string $token): void
    {
        $this->call(fn (): mixed => $this->client()->eval(
            "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) end return 0",
            [OtpKeys::lock($challengeId), $token],
            1,
        ));
    }

    public function deleteChallenge(string $challengeId, string $phoneHmac): void
    {
        $this->call(fn (): mixed => $this->client()->del(OtpKeys::challenge($challengeId)));
        $this->clearActivePointer($challengeId, $phoneHmac);
    }

    public function incrementFailure(string $challengeId, string $phoneHmac): string
    {
        $maxAttempts = (int) config('medifind.otp.max_attempts');

        if ($maxAttempts < 1) {
            throw new RuntimeException('OTP attempt limit is invalid.');
        }

        $result = $this->call(fn (): mixed => $this->client()->eval(<<<'LUA'
if redis.call('EXISTS', KEYS[1]) == 0 then
    return 'missing'
end
local attempts = redis.call('HINCRBY', KEYS[1], 'attempts', 1)
if tonumber(attempts) >= tonumber(ARGV[2]) then
    redis.call('DEL', KEYS[1])
    if redis.call('GET', KEYS[2]) == ARGV[1] then
        redis.call('DEL', KEYS[2])
    end
    return 'exhausted'
end
return 'mismatch'
LUA, [OtpKeys::challenge($challengeId), OtpKeys::active($phoneHmac), $challengeId, (string) $maxAttempts], 2));

        return is_string($result) ? $result : 'mismatch';
    }

    public function consume(string $challengeId, string $phoneHmac): void
    {
        $this->deleteChallenge($challengeId, $phoneHmac);
    }

    public function discardUndelivered(string $challengeId, string $phoneHmac): void
    {
        $this->deleteChallenge($challengeId, $phoneHmac);
    }

    public function forceExpired(string $challengeId): void
    {
        $this->call(fn (): mixed => $this->client()->hSet(OtpKeys::challenge($challengeId), 'expires_at', (string) ($this->time()[0] - 5)));
    }

    public function clearCooldown(string $phoneHmac): void
    {
        $this->call(fn (): mixed => $this->client()->del(OtpKeys::cooldown($phoneHmac)));
    }

    public function zcard(string $key): int
    {
        return (int) $this->call(fn (): mixed => $this->client()->zCard($key));
    }

    public function exists(string $key): bool
    {
        return (int) $this->call(fn (): mixed => $this->client()->exists($key)) > 0;
    }

    public function get(string $key): ?string
    {
        $value = $this->call(fn (): mixed => $this->client()->get($key));

        return is_string($value) ? $value : null;
    }

    /**
     * @return list<string>
     */
    public function members(string $key): array
    {
        $members = $this->call(fn (): mixed => $this->client()->zRange($key, 0, -1));

        if (! is_array($members)) {
            return [];
        }

        $values = [];

        foreach ($members as $member) {
            if (is_string($member)) {
                $values[] = $member;
            }
        }

        return $values;
    }

    /**
     * @param  list<string>  $arguments
     */
    public function evalSameScore(string $key, array $arguments): int
    {
        $result = $this->call(fn (): mixed => $this->client()->eval(<<<'LUA'
local time = redis.call('TIME')
local score = tonumber(time[1]) * 1000
redis.call('ZADD', KEYS[1], score, ARGV[1])
redis.call('ZADD', KEYS[1], score, ARGV[2])
return redis.call('ZCARD', KEYS[1])
LUA, array_merge([$key], $arguments), 1));

        return (int) $result;
    }

    public function deleteNamespace(): void
    {
        TestingSafety::assertSafeForTestRedisCleanup();

        if ($this->host() !== 'redis_test') {
            throw new RuntimeException('OTP cleanup is only allowed against redis_test.');
        }

        $iterator = null;

        do {
            $keys = $this->call(function () use (&$iterator): mixed {
                return $this->client()->scan($iterator, OtpKeys::PREFIX.'*', 200);
            });

            if (! is_array($keys)) {
                break;
            }

            foreach ($keys as $key) {
                $key = (string) $key;

                if (str_starts_with($key, OtpKeys::PREFIX)) {
                    $this->call(fn (): mixed => $this->client()->del($key));
                }
            }
        } while ($iterator !== 0 && $iterator !== '0');
    }

    private function clearActivePointer(string $challengeId, string $phoneHmac): void
    {
        $this->call(fn (): mixed => $this->client()->eval(<<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('DEL', KEYS[1])
end
return 0
LUA, [OtpKeys::active($phoneHmac), $challengeId], 1));
    }

    private function call(callable $command): mixed
    {
        try {
            return $command();
        } catch (RedisException) {
            throw new OtpStoreUnavailable;
        }
    }
}
