<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\IsolatedHttpRuntime;
use Illuminate\Console\Command;

final class AssertIsolatedHttpRuntimeCommand extends Command
{
    protected $signature = 'medifind:assert-isolated-http-runtime';

    protected $description = 'Fail closed unless this process is the isolated mobile HTTP test runtime.';

    public function handle(): int
    {
        IsolatedHttpRuntime::assert();
        IsolatedHttpRuntime::assertRedisReachable();

        $this->info('Isolated HTTP runtime checks passed.');

        return self::SUCCESS;
    }
}
