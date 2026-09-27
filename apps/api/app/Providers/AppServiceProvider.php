<?php

namespace App\Providers;

use App\Identity\Otp\InMemoryOtpProvider;
use App\Identity\Otp\LocalConfiguredOtpProvider;
use App\Identity\Otp\OtpConfiguration;
use App\Identity\Otp\OtpRedis;
use App\Identity\Otp\SmsOtpProvider;
use App\Models\Medicine;
use App\Models\MedicineUse;
use App\Models\Pharmacy;
use App\Models\PharmacyInventory;
use App\Models\User;
use App\Policies\MedicinePolicy;
use App\Policies\MedicineUsePolicy;
use App\Policies\PharmacyInventoryPolicy;
use App\Policies\PharmacyPolicy;
use App\Prescriptions\Extraction\PrescriptionExtractor;
use App\Prescriptions\Extraction\SyntheticPrescriptionExtractor;
use App\Prescriptions\Extraction\UnconfiguredPrescriptionExtractor;
use App\Prescriptions\ObjectBucketMetadata;
use App\Prescriptions\PrescriptionErasureGate;
use App\Prescriptions\PrescriptionErasurePolicy;
use App\Prescriptions\PrescriptionStorageGuard;
use App\Prescriptions\S3ObjectBucketMetadata;
use App\Support\TestingSafety;
use Illuminate\Auth\Events\Login;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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
        $this->app->singleton(ObjectBucketMetadata::class, S3ObjectBucketMetadata::class);
        $this->app->singleton(PrescriptionErasureGate::class, function ($app): PrescriptionErasureGate {
            return new PrescriptionErasureGate(
                $app->make(PrescriptionStorageGuard::class),
                $app->make(PrescriptionErasurePolicy::class),
                $app->make(ObjectBucketMetadata::class),
            );
        });
        $this->app->bind(PrescriptionExtractor::class, function (): PrescriptionExtractor {
            $choice = (string) config('medifind.prescriptions.extractor');

            if ($choice === 'synthetic' && app()->environment('local', 'testing')) {
                return new SyntheticPrescriptionExtractor;
            }

            return new UnconfiguredPrescriptionExtractor;
        });
    }

    public function boot(): void
    {
        Gate::policy(Pharmacy::class, PharmacyPolicy::class);
        Gate::policy(Medicine::class, MedicinePolicy::class);
        Gate::policy(MedicineUse::class, MedicineUsePolicy::class);
        Gate::policy(PharmacyInventory::class, PharmacyInventoryPolicy::class);

        $this->app->make(OtpConfiguration::class)->assertRuntimeSafe();

        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                session(['medifind.session_generation' => $event->user->session_generation]);
            }
        });

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
