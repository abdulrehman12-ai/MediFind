<?php

declare(strict_types=1);

use App\Jobs\PingJob;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$token = (string) Str::uuid();
Cache::forget('queue-smoke:'.$token);
dispatch(new PingJob($token));

$deadline = time() + 20;
$value = null;
while (time() < $deadline) {
    $value = Cache::get('queue-smoke:'.$token);
    if ($value === 'ok') {
        fwrite(STDOUT, "queue-smoke=ok\n");
        exit(0);
    }
    usleep(250000);
}

fwrite(STDERR, "queue-smoke failed\n");
exit(1);
