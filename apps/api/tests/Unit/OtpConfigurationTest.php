<?php

namespace Tests\Unit;

use App\Identity\Otp\OtpConfiguration;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class OtpConfigurationTest extends TestCase
{
    public function test_production_rejects_local_provider(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['medifind.otp.provider' => 'local', 'medifind.otp.local_code' => null]);

        $this->expectExceptionMessage('OTP provider is not allowed in production.');
        app(OtpConfiguration::class)->assertRuntimeSafe();
    }

    public function test_production_rejects_memory_provider(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['medifind.otp.provider' => 'memory', 'medifind.otp.local_code' => null]);

        $this->expectExceptionMessage('OTP provider is not allowed in production.');
        app(OtpConfiguration::class)->assertRuntimeSafe();
    }

    public function test_production_rejects_local_otp_code(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['medifind.otp.provider' => 'sms', 'medifind.otp.local_code' => '123456']);

        $this->expectExceptionMessage('LOCAL_OTP_CODE is not allowed in production.');
        app(OtpConfiguration::class)->assertRuntimeSafe();
    }

    public function test_production_rejects_unconfigured_provider(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['medifind.otp.provider' => 'sms', 'medifind.otp.local_code' => null]);

        $this->expectExceptionMessage('OTP provider is not configured for production.');
        app(OtpConfiguration::class)->assertRuntimeSafe();
    }

    public function test_missing_hmac_key_is_rejected_outside_package_discovery(): void
    {
        config(['medifind.otp.hmac_key' => '']);

        $this->expectExceptionMessage('OTP_HMAC_KEY is missing or invalid.');
        app(OtpConfiguration::class)->assertRuntimeSafe();
    }

    public function test_local_provider_requires_a_six_digit_code(): void
    {
        config(['medifind.otp.provider' => 'local', 'medifind.otp.local_code' => '']);

        $this->expectExceptionMessage('LOCAL_OTP_CODE is missing or invalid.');
        app(OtpConfiguration::class)->assertRuntimeSafe();
    }

    public function test_local_provider_rejects_a_short_code(): void
    {
        config(['medifind.otp.provider' => 'local', 'medifind.otp.local_code' => '12345']);

        $this->expectExceptionMessage('LOCAL_OTP_CODE is missing or invalid.');
        app(OtpConfiguration::class)->assertRuntimeSafe();
    }

    public function test_memory_provider_is_limited_to_testing(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');
        config(['medifind.otp.provider' => 'memory', 'medifind.otp.local_code' => null]);

        $this->expectExceptionMessage('OTP memory provider is only available in testing.');
        app(OtpConfiguration::class)->assertRuntimeSafe();
    }

    public function test_testing_memory_provider_boots_without_a_local_code(): void
    {
        config(['medifind.otp.provider' => 'memory', 'medifind.otp.local_code' => '']);

        app(OtpConfiguration::class)->assertRuntimeSafe();
        $this->assertTrue(true);
    }

    public function test_package_discover_skips_otp_validation(): void
    {
        $argv = $_SERVER['argv'] ?? [];
        $_SERVER['argv'][] = 'package:discover';
        config(['medifind.otp.hmac_key' => '', 'medifind.otp.provider' => 'local']);

        try {
            app(OtpConfiguration::class)->assertRuntimeSafe();
            $this->assertTrue(true);
        } finally {
            $_SERVER['argv'] = $argv;
        }
    }

    #[Group('phase1b-runtime')]
    public function test_console_entrypoints_fail_closed_in_production(): void
    {
        foreach (['about', 'schedule:list'] as $command) {
            $result = $this->runArtisan($command, [
                'APP_ENV' => 'production',
                'SMS_OTP_PROVIDER' => 'local',
                'LOCAL_OTP_CODE' => '',
            ]);

            $this->assertNotSame(0, $result['exit']);
            $this->assertStringContainsString('OTP provider is not allowed in production.', $result['output']);
            $this->assertStringNotContainsString((string) config('medifind.otp.hmac_key'), $result['output']);
            $this->assertStringNotContainsString((string) config('app.key'), $result['output']);
        }

        $queue = $this->runArtisan('queue:work', [
            'APP_ENV' => 'production',
            'SMS_OTP_PROVIDER' => 'memory',
            'LOCAL_OTP_CODE' => '',
        ], ['--once' => true]);

        $this->assertNotSame(0, $queue['exit']);
        $this->assertStringContainsString('OTP provider is not allowed in production.', $queue['output']);
        $this->assertStringNotContainsString((string) config('medifind.otp.hmac_key'), $queue['output']);
    }

    /**
     * @param  array<string, string>  $overrides
     * @param  array<string, mixed>  $arguments
     * @return array{exit: int, output: string}
     */
    private function runArtisan(string $command, array $overrides, array $arguments = []): array
    {
        $env = [];

        foreach (array_merge($_SERVER, $overrides) as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $env[$key] = $value;
            }
        }

        $process = new Process(
            array_merge([PHP_BINARY, base_path('artisan'), $command], $this->argumentList($arguments)),
            base_path(),
            $env,
        );
        $process->run();

        return [
            'exit' => $process->getExitCode() ?? 1,
            'output' => $process->getOutput().$process->getErrorOutput(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<string>
     */
    private function argumentList(array $arguments): array
    {
        $list = [];

        foreach ($arguments as $key => $value) {
            if ($value === true) {
                $list[] = (string) $key;
            }
        }

        return $list;
    }
}
