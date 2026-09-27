<?php

declare(strict_types=1);

namespace App\Prescriptions;

use App\Exceptions\ApiProblem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PrescriptionObjectStore
{
    public function put(string $key, string $bytes): void
    {
        try {
            app(PrescriptionErasureGate::class)->assertReady();
        } catch (ApiProblem $exception) {
            throw $exception;
        } catch (Throwable) {
            Log::warning('prescription_bucket_erasure_rejected');

            throw new ApiProblem('storage_unavailable', 'The prescription could not be stored.', 503);
        }

        try {
            $stored = Storage::disk($this->disk())->put($key, $bytes);
        } catch (Throwable) {
            Log::warning('prescription_object_store_failed');

            throw new ApiProblem('storage_unavailable', 'The prescription could not be stored.', 503);
        }

        if ($stored !== true) {
            Log::warning('prescription_object_store_failed');

            throw new ApiProblem('storage_unavailable', 'The prescription could not be stored.', 503);
        }
    }

    public function get(string $key): string
    {
        app(PrescriptionStorageGuard::class)->assertConfigured();

        try {
            $bytes = Storage::disk($this->disk())->get($key);
        } catch (Throwable) {
            Log::warning('prescription_object_read_failed');

            throw new ApiProblem('storage_unavailable', 'The prescription file is unavailable.', 503);
        }

        if (! is_string($bytes) || $bytes === '') {
            throw new ApiProblem('storage_unavailable', 'The prescription file is unavailable.', 503);
        }

        return $bytes;
    }

    public function delete(string $key): bool
    {
        try {
            app(PrescriptionErasureGate::class)->assertReady();
        } catch (Throwable) {
            Log::warning('prescription_bucket_erasure_rejected');

            return false;
        }

        try {
            $deleted = Storage::disk($this->disk())->delete($key);
        } catch (Throwable) {
            Log::warning('prescription_object_delete_failed');

            return false;
        }

        if ($deleted !== true) {
            Log::warning('prescription_object_delete_failed');

            return false;
        }

        return ! $this->exists($key);
    }

    /**
     * @return list<array{key: string, modified_at: int}>
     */
    public function listPage(?string $afterKey, int $limit): array
    {
        app(PrescriptionStorageGuard::class)->assertConfigured();
        $limit = max(1, $limit);
        $driver = (string) config('filesystems.disks.'.$this->disk().'.driver');

        if ($driver === 's3') {
            return $this->listS3($afterKey, $limit);
        }

        $files = Storage::disk($this->disk())->files('rx');
        sort($files);
        $page = [];

        foreach ($files as $key) {
            if (! str_starts_with($key, 'rx/') || str_contains($key, '..')) {
                continue;
            }

            if ($afterKey !== null && $afterKey !== '' && strcmp($key, $afterKey) <= 0) {
                continue;
            }

            try {
                $modified = Storage::disk($this->disk())->lastModified($key);
            } catch (Throwable) {
                $modified = 0;
            }

            $page[] = ['key' => $key, 'modified_at' => $modified];

            if (count($page) >= $limit) {
                break;
            }
        }

        return $page;
    }

    /**
     * @return list<array{key: string, modified_at: int}>
     */
    private function listS3(?string $afterKey, int $limit): array
    {
        $bucket = trim((string) config('filesystems.disks.'.$this->disk().'.bucket'));
        $disk = Storage::disk($this->disk());

        if (! method_exists($disk, 'getClient')) {
            throw new ApiProblem('storage_unavailable', 'The prescription file is unavailable.', 503);
        }

        $client = $disk->getClient();
        $arguments = [
            'Bucket' => $bucket,
            'Prefix' => 'rx/',
            'MaxKeys' => $limit,
        ];

        if ($afterKey !== null && $afterKey !== '') {
            $arguments['StartAfter'] = $afterKey;
        }

        $result = $client->listObjectsV2($arguments);
        $page = [];

        foreach ($result['Contents'] ?? [] as $item) {
            $key = $item['Key'] ?? null;

            if (! is_string($key) || ! str_starts_with($key, 'rx/') || str_contains($key, '..')) {
                continue;
            }

            $modified = $item['LastModified'] ?? null;
            $page[] = [
                'key' => $key,
                'modified_at' => $modified instanceof \DateTimeInterface ? $modified->getTimestamp() : 0,
            ];
        }

        return $page;
    }

    public function exists(string $key): bool
    {
        try {
            return Storage::disk($this->disk())->exists($key);
        } catch (Throwable) {
            Log::warning('prescription_object_read_failed');

            return true;
        }
    }

    public function disk(): string
    {
        return (string) config('medifind.prescriptions.disk');
    }
}
