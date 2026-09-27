<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Exceptions\ApiProblem;
use App\Models\Prescription;
use App\Models\PrescriptionObjectHold;
use App\Models\User;
use App\Prescriptions\ObjectBucketMetadata;
use App\Prescriptions\PrescriptionErasureGate;
use App\Prescriptions\PrescriptionErasurePolicy;
use App\Prescriptions\PrescriptionObjectStore;
use App\Prescriptions\PrescriptionStorageGuard;
use App\Prescriptions\PrescriptionWorkflow;
use App\Prescriptions\S3ObjectBucketMetadata;
use Aws\Command;
use Aws\S3\Exception\S3Exception;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class PrescriptionStorageContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CitySeeder::class);
        Storage::disk('prescriptions_testing')->delete(Storage::disk('prescriptions_testing')->allFiles('rx'));
    }

    public function test_s3_configuration_fails_closed_without_calling_aws(): void
    {
        Config::set('medifind.prescriptions.disk', 'prescriptions');
        Config::set('filesystems.disks.prescriptions.bucket', '');
        Config::set('filesystems.disks.prescriptions.region', '');
        Config::set('filesystems.disks.prescriptions.endpoint', null);

        try {
            try {
                app(PrescriptionStorageGuard::class)->assertConfigured();
                $this->fail('Missing bucket configuration was accepted.');
            } catch (ApiProblem $problem) {
                $this->assertSame('storage_unavailable', $problem->errorCode);
                $this->assertSame(503, $problem->status);
            }

            Config::set('filesystems.disks.prescriptions.bucket', 'configured-bucket');
            Config::set('filesystems.disks.prescriptions.region', 'ap-southeast-1');
            Config::set('filesystems.disks.prescriptions.endpoint', 'http://storage.internal');

            try {
                app(PrescriptionStorageGuard::class)->assertConfigured();
                $this->fail('A custom storage endpoint was accepted.');
            } catch (ApiProblem $problem) {
                $this->assertSame('storage_unavailable', $problem->errorCode);
            }
        } finally {
            Config::set('medifind.prescriptions.disk', 'prescriptions_testing');
            Config::set('filesystems.disks.prescriptions.endpoint', null);
        }
    }

    public function test_versioned_and_locked_buckets_cannot_claim_erasure(): void
    {
        $policy = new PrescriptionErasurePolicy;
        $policy->assertBucketAllowsErasure(null, false);

        foreach (['Enabled', 'Suspended', 'Disabled', 'enabled'] as $status) {
            try {
                $policy->assertBucketAllowsErasure($status, false);
                $this->fail('Versioning status was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('unversioned', $exception->getMessage());
            }
        }

        try {
            $policy->assertBucketAllowsErasure(null, true);
            $this->fail('Object Lock was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Object Lock', $exception->getMessage());
        }
    }

    public function test_delete_refuses_a_versioned_bucket_without_a_network_call(): void
    {
        Config::set('medifind.prescriptions.disk', 'prescriptions');
        Config::set('filesystems.disks.prescriptions.driver', 's3');
        Config::set('filesystems.disks.prescriptions.bucket', 'configured-bucket');
        Config::set('filesystems.disks.prescriptions.region', 'ap-southeast-1');
        Config::set('filesystems.disks.prescriptions.endpoint', null);
        Config::set('filesystems.disks.prescriptions.url', null);
        $this->app->instance(ObjectBucketMetadata::class, new class implements ObjectBucketMetadata
        {
            public function versioningStatus(string $bucket): ?string
            {
                return 'Enabled';
            }

            public function objectLockEnabled(string $bucket): bool
            {
                return false;
            }
        });
        $this->app->forgetInstance(PrescriptionErasureGate::class);

        try {
            $this->assertFalse((new PrescriptionObjectStore)->delete('rx/'.Str::uuid()->toString()));
        } finally {
            Config::set('medifind.prescriptions.disk', 'prescriptions_testing');
            $this->app->forgetInstance(ObjectBucketMetadata::class);
            $this->app->forgetInstance(PrescriptionErasureGate::class);
        }
    }

    public function test_unreadable_bucket_metadata_does_not_claim_erasure(): void
    {
        Config::set('medifind.prescriptions.disk', 'prescriptions');
        Config::set('filesystems.disks.prescriptions.driver', 's3');
        Config::set('filesystems.disks.prescriptions.bucket', 'configured-bucket');
        Config::set('filesystems.disks.prescriptions.region', 'ap-southeast-1');
        Config::set('filesystems.disks.prescriptions.endpoint', null);
        Config::set('filesystems.disks.prescriptions.url', null);
        $this->app->instance(ObjectBucketMetadata::class, new class implements ObjectBucketMetadata
        {
            public function versioningStatus(string $bucket): ?string
            {
                throw new RuntimeException('credential provider failed');
            }

            public function objectLockEnabled(string $bucket): bool
            {
                return false;
            }
        });
        $this->app->forgetInstance(PrescriptionErasureGate::class);

        try {
            $this->assertFalse((new PrescriptionObjectStore)->delete('rx/'.Str::uuid()->toString()));
            $this->artisan('prescriptions:prune-orphans')->assertFailed();
        } finally {
            Config::set('medifind.prescriptions.disk', 'prescriptions_testing');
            $this->app->forgetInstance(ObjectBucketMetadata::class);
            $this->app->forgetInstance(PrescriptionErasureGate::class);
        }
    }

    public function test_a_late_put_after_cleanup_is_removed_without_deleting_a_live_object(): void
    {
        $customer = User::query()->forceCreate([
            'public_id' => (string) Str::uuid(),
            'email' => 'rx-reconcile@example.test',
            'phone_e164' => '+923009990001',
            'password' => null,
            'role' => UserRole::Customer,
            'status' => AccountStatus::Active,
        ]);
        $live = 'rx/'.Str::uuid()->toString();
        $late = 'rx/'.Str::uuid()->toString();
        $young = 'rx/'.Str::uuid()->toString();
        $disk = Storage::disk('prescriptions_testing');
        $disk->put($live, 'live');
        $disk->put($late, 'late');
        $disk->put($young, 'young');
        touch($disk->path($live), time() - 7200);
        touch($disk->path($late), time() - 7200);
        Prescription::query()->forceCreate([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'object_key' => $live,
            'display_name' => 'live.png',
            'mime_type' => 'image/png',
            'byte_size' => 4,
            'sha256' => hash('sha256', 'live'),
            'extraction_state' => 'failed',
            'share_state' => 'none',
            'object_state' => 'stored',
        ]);
        PrescriptionObjectHold::query()->forceCreate([
            'object_key' => $late,
            'status' => 'purged',
            'created_at' => now()->subHours(2),
        ]);

        $this->artisan('prescriptions:prune-orphans')->assertSuccessful();

        $this->assertTrue($disk->exists($live));
        $this->assertTrue($disk->exists($young));
        $this->assertFalse($disk->exists($late));
    }

    public function test_the_testing_disk_is_rejected_outside_the_testing_environment(): void
    {
        $this->app['env'] = 'production';

        try {
            app(PrescriptionStorageGuard::class)->assertConfigured();
            $this->fail('The local testing disk was accepted outside testing.');
        } catch (ApiProblem $problem) {
            $this->assertSame('storage_unavailable', $problem->errorCode);
            $this->assertSame(503, $problem->status);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_upload_and_replacement_stop_before_writing_when_erasure_is_impossible(): void
    {
        $customer = User::query()->forceCreate([
            'public_id' => (string) Str::uuid(),
            'email' => 'rx-gate@example.test',
            'phone_e164' => '+923009990002',
            'password' => null,
            'role' => UserRole::Customer,
            'status' => AccountStatus::Active,
        ]);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        $this->assertIsString($png);
        $upload = UploadedFile::fake()->createWithContent('scan.png', $png);
        $row = app(PrescriptionWorkflow::class)->upload($customer, $upload);
        $this->usePrescriptionS3Disk();
        $this->bindMetadata('Enabled', false);
        $disk = new RecordingPrescriptionDisk;
        Storage::set('prescriptions', $disk);

        try {
            (new PrescriptionObjectStore)->put('rx/'.Str::uuid()->toString(), $png);
            $this->fail('An upload wrote to a bucket that cannot be erased.');
        } catch (ApiProblem $problem) {
            $this->assertSame('storage_unavailable', $problem->errorCode);
        }

        $this->assertSame(0, $disk->writes);

        try {
            app(PrescriptionWorkflow::class)->replaceFile($customer, $row, $upload);
            $this->fail('A replacement wrote to a bucket that cannot be erased.');
        } catch (ApiProblem $problem) {
            $this->assertSame('storage_unavailable', $problem->errorCode);
        } finally {
            Storage::forgetDisk('prescriptions');
            $this->restorePrescriptionTestingDisk();
        }

        $this->assertSame(0, $disk->writes);
        $this->assertTrue(Storage::disk('prescriptions_testing')->exists($row->object_key));
    }

    public function test_delete_stops_after_the_bucket_status_changes(): void
    {
        $this->usePrescriptionS3Disk();
        $metadata = $this->bindMetadata(null, false);
        $disk = new RecordingPrescriptionDisk;
        Storage::set('prescriptions', $disk);
        $store = new PrescriptionObjectStore;
        $key = 'rx/'.Str::uuid()->toString();

        try {
            $store->put($key, 'bytes');
            $this->assertSame(1, $disk->writes);
            $metadata->versioning = 'Enabled';

            try {
                $store->put('rx/'.Str::uuid()->toString(), 'replacement');
                $this->fail('A later upload ignored the new bucket status.');
            } catch (ApiProblem $problem) {
                $this->assertSame('storage_unavailable', $problem->errorCode);
            }

            $this->assertSame(1, $disk->writes);
            $metadata->versioning = null;
            $this->assertTrue($store->delete($key));
            $this->assertSame(1, $disk->deletes);
            $metadata->versioning = 'Suspended';
            $this->assertFalse($store->delete($key));
            $this->assertSame(1, $disk->deletes);
        } finally {
            Storage::forgetDisk('prescriptions');
            $this->restorePrescriptionTestingDisk();
        }
    }

    public function test_unknown_bucket_metadata_fails_closed(): void
    {
        $this->usePrescriptionS3Disk();
        $client = new ScriptedBucketClient;
        Storage::set('prescriptions', new ClientBackedDisk($client));
        $metadata = new S3ObjectBucketMetadata;

        try {
            $status = $metadata->versioningStatus('configured-bucket');
            $this->assertSame('Disabled', $status);

            try {
                (new PrescriptionErasurePolicy)->assertBucketAllowsErasure($status, false);
                $this->fail('An unknown versioning status was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('unversioned', $exception->getMessage());
            }

            try {
                $metadata->objectLockEnabled('configured-bucket');
                $this->fail('An unknown Object Lock status was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('not recognized', $exception->getMessage());
            }

            $client->missingLock = true;
            $this->assertFalse($metadata->objectLockEnabled('configured-bucket'));

            $client->versioning = [];
            $this->assertNull($metadata->versioningStatus('configured-bucket'));
            (new PrescriptionErasurePolicy)->assertBucketAllowsErasure(null, false);

            foreach (['', null, 1, ['Enabled']] as $status) {
                $client->versioning = ['Status' => $status];

                try {
                    $metadata->versioningStatus('configured-bucket');
                    $this->fail('A malformed versioning status was accepted.');
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('could not be read', $exception->getMessage());
                }
            }

            foreach ([['Enabled'], 'Enabled', 0] as $response) {
                $client->versioning = $response;

                try {
                    $metadata->versioningStatus('configured-bucket');
                    $this->fail('A malformed versioning response was accepted.');
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('could not be read', $exception->getMessage());
                }
            }

            $client->versioning = ['Status' => ''];
            $disk = Storage::disk('prescriptions');
            $this->assertInstanceOf(ClientBackedDisk::class, $disk);

            try {
                (new PrescriptionObjectStore)->put('rx/'.Str::uuid()->toString(), 'bytes');
                $this->fail('A malformed versioning response allowed a write.');
            } catch (ApiProblem $problem) {
                $this->assertSame('storage_unavailable', $problem->errorCode);
            }

            $this->assertSame(0, $disk->writes);
        } finally {
            Storage::forgetDisk('prescriptions');
            $this->restorePrescriptionTestingDisk();
        }
    }

    private function usePrescriptionS3Disk(): void
    {
        Config::set('medifind.prescriptions.disk', 'prescriptions');
        Config::set('filesystems.disks.prescriptions.driver', 's3');
        Config::set('filesystems.disks.prescriptions.bucket', 'configured-bucket');
        Config::set('filesystems.disks.prescriptions.region', 'ap-southeast-1');
        Config::set('filesystems.disks.prescriptions.endpoint', null);
        Config::set('filesystems.disks.prescriptions.url', null);
    }

    private function restorePrescriptionTestingDisk(): void
    {
        Config::set('medifind.prescriptions.disk', 'prescriptions_testing');
        $this->app->forgetInstance(ObjectBucketMetadata::class);
        $this->app->forgetInstance(PrescriptionErasureGate::class);
    }

    private function bindMetadata(?string $versioning, bool $locked): MutableBucketMetadata
    {
        $metadata = new MutableBucketMetadata;
        $metadata->versioning = $versioning;
        $metadata->locked = $locked;
        $this->app->instance(ObjectBucketMetadata::class, $metadata);
        $this->app->forgetInstance(PrescriptionErasureGate::class);

        return $metadata;
    }
}

final class MutableBucketMetadata implements ObjectBucketMetadata
{
    public ?string $versioning = null;

    public bool $locked = false;

    public function versioningStatus(string $bucket): ?string
    {
        return $this->versioning;
    }

    public function objectLockEnabled(string $bucket): bool
    {
        return $this->locked;
    }
}

final class ScriptedBucketClient
{
    public bool $missingLock = false;

    public mixed $versioning = ['Status' => 'Disabled'];

    /** @param  array<string, mixed>  $args */
    public function getBucketVersioning(array $args): mixed
    {
        return $this->versioning;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function getObjectLockConfiguration(array $args): array
    {
        if ($this->missingLock) {
            throw new S3Exception('missing', new Command('GetObjectLockConfiguration'), [
                'code' => 'ObjectLockConfigurationNotFoundError',
            ]);
        }

        return ['ObjectLockConfiguration' => ['ObjectLockEnabled' => 'Disabled']];
    }
}

final class ClientBackedDisk
{
    public int $writes = 0;

    public function __construct(private object $client) {}

    public function getClient(): object
    {
        return $this->client;
    }

    public function put(string $key, string $bytes): bool
    {
        $this->writes++;

        return true;
    }
}

final class RecordingPrescriptionDisk
{
    public int $writes = 0;

    public int $deletes = 0;

    public function put(string $key, string $bytes): bool
    {
        $this->writes++;

        return true;
    }

    public function delete(string $key): bool
    {
        $this->deletes++;

        return true;
    }

    public function exists(string $key): bool
    {
        return false;
    }
}
