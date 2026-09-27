<?php

namespace Tests\Support;

use App\Exceptions\ApiProblem;
use App\Prescriptions\PrescriptionObjectStore;
use RuntimeException;

final class ScriptedObjectStore extends PrescriptionObjectStore
{
    public string $mode = 'real';

    public string $putMode = 'real';

    /** @var array<string, true> */
    public array $denyDelete = [];

    public function put(string $key, string $bytes): void
    {
        if ($this->putMode === 'write_then_throw') {
            parent::put($key, $bytes);

            throw new RuntimeException('storage');
        }

        if ($this->putMode === 'write_then_false') {
            parent::put($key, $bytes);

            throw new ApiProblem('storage_unavailable', 'The prescription could not be stored.', 503);
        }

        if ($this->putMode === 'throw') {
            throw new RuntimeException('storage');
        }

        if ($this->putMode === 'false') {
            throw new ApiProblem('storage_unavailable', 'The prescription could not be stored.', 503);
        }

        parent::put($key, $bytes);
    }

    public function delete(string $key): bool
    {
        if (isset($this->denyDelete[$key])) {
            return false;
        }

        if ($this->mode === 'throw') {
            throw new RuntimeException('storage');
        }

        if ($this->mode === 'false') {
            return false;
        }

        return parent::delete($key);
    }
}
