<?php

declare(strict_types=1);

$path = $argv[1] ?? '/out/local.secrets';

$password = static fn (): string => bin2hex(random_bytes(24));
$otpHmacKey = bin2hex(random_bytes(32));
$otpTestHmacKey = bin2hex(random_bytes(32));

if (hash_equals($otpHmacKey, $otpTestHmacKey)) {
    fwrite(STDERR, "OTP_HMAC_KEY and OTP_TEST_HMAC_KEY must differ\n");
    exit(1);
}

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
    'OTP_HMAC_KEY='.$otpHmacKey,
    'OTP_TEST_HMAC_KEY='.$otpTestHmacKey,
    'SMS_OTP_PROVIDER=local',
    'LOCAL_OTP_CODE='.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
    '',
]);

if (file_put_contents($path, $contents) === false) {
    fwrite(STDERR, "Unable to write secrets file\n");
    exit(1);
}

chmod($path, 0600);
fwrite(STDOUT, "Wrote gitignored secrets file\n");
