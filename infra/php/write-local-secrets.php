<?php

declare(strict_types=1);

$path = $argv[1] ?? '/out/local.secrets';

$password = static fn (): string => bin2hex(random_bytes(24));

$contents = implode("\n", [
    'APP_NAME=MediFind',
    'APP_ENV=local',
    'APP_DEBUG=false',
    'APP_KEY=base64:'.base64_encode(random_bytes(32)),
    'APP_URL=http://127.0.0.1:8080',
    'LOG_CHANNEL=stderr',
    'LOG_LEVEL=info',
    'POSTGRES_USER=postgres',
    'POSTGRES_PASSWORD='.$password(),
    'POSTGRES_DB=postgres',
    'POSTGRES_APP_PASSWORD='.$password(),
    'POSTGRES_TEST_PASSWORD='.$password(),
    'REDIS_PASSWORD='.$password(),
    'REDIS_TEST_PASSWORD='.$password(),
    '',
]);

if (file_put_contents($path, $contents) === false) {
    fwrite(STDERR, "Unable to write secrets file\n");
    exit(1);
}

chmod($path, 0600);
fwrite(STDOUT, "Wrote gitignored secrets file\n");
