<?php

namespace App\Providers;

use App\Identity\Otp\InMemoryOtpProvider;
use App\Identity\Otp\LocalConfiguredOtpProvider;
use App\Identity\Otp\OtpConfiguration;
use App\Identity\Otp\OtpRedis;
use App\Identity\Otp\SmsOtpProvider;
use App\Support\TestingSafety;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InMemoryOtpProvider::class);
        $this->app->singleton(OtpRedis::class);
        $this->app->bind(SmsOtpProvider::class, function ($app): SmsOtpProvider {
            return match ((string) config('medifind.otp.provider')) {
                'memory' => $app->make(InMemoryOtpProvider::class),
                'local' => $app->make(LocalConfiguredOtpProvider::class),
                default => throw new RuntimeException('OTP provider is not configured.'),
            };
        });
    }

    public function boot(): void
    {
        $this->app->make(OtpConfiguration::class)->assertRuntimeSafe();

        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            $destructive = [
                'migrate:fresh',
                'migrate:refresh',
                'migrate:reset',
                'db:wipe',
            ];

            if (in_array($event->command, $destructive, true)) {
                TestingSafety::assertSafeForDestructiveDatabaseWork();
            }
        });
    }
}
