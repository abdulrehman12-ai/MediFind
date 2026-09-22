<?php

declare(strict_types=1);

$code = getenv('LOCAL_OTP_CODE');

if (! is_string($code) || preg_match('/^\d{6}$/', $code) !== 1) {
    fwrite(STDERR, "local_otp_unconfigured\n");
    exit(1);
}

$phone = '0300'.random_int(1000000, 9999999);

$http = static function (string $method, string $path, array $json): array {
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => "Accept: application/json\r\nContent-Type: application/json\r\n",
            'content' => json_encode($json, JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
            'timeout' => 10,
        ],
    ]);
    $body = file_get_contents('http://nginx'.$path, false, $context);

    if ($body === false) {
        return [0, ''];
    }

    $status = 0;

    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $matches) === 1) {
            $status = (int) $matches[1];
        }
    }

    return [$status, $body];
};

[$requestStatus, $requestBody] = $http('POST', '/api/v1/auth/otp/request', ['phone_number' => $phone]);
$requestJson = json_decode($requestBody, true);
$requestJsonValid = json_last_error() === JSON_ERROR_NONE && is_array($requestJson);
$challenge = $requestJsonValid ? (string) ($requestJson['data']['challenge_id'] ?? '') : '';
$retryAfter = $requestJsonValid ? ($requestJson['data']['retry_after'] ?? null) : null;
$requestId = $requestJsonValid ? ($requestJson['meta']['request_id'] ?? null) : null;

[$verifyStatus, $verifyBody] = $http('POST', '/api/v1/auth/otp/verify', [
    'phone_number' => $phone,
    'challenge_id' => $challenge,
    'otp' => $code,
]);
$verifyJson = json_decode($verifyBody, true);
$verifyJsonValid = json_last_error() === JSON_ERROR_NONE && is_array($verifyJson);
$masked = $verifyJsonValid ? (string) ($verifyJson['data']['customer']['phone_masked'] ?? '') : '';
$verifyId = $verifyJsonValid ? ($verifyJson['meta']['request_id'] ?? null) : null;
$token = $verifyJsonValid ? ($verifyJson['data']['access_token'] ?? null) : null;
$customerId = $verifyJsonValid ? ($verifyJson['data']['customer']['id'] ?? null) : null;
$e164 = '+92'.substr($phone, 1);
$combined = $requestBody.$verifyBody;

$maskedOk = preg_match('/^\+92\d{3}\*{3}\d{4}$/', $masked) === 1;
$rawAbsent = ! str_contains($requestBody, $e164)
    && ! str_contains($verifyBody, $e164)
    && ! str_contains($requestBody, $code)
    && ! str_contains($verifyBody, $code);
$phoneFieldsAbsent = ! str_contains($combined, 'phone_number') && ! str_contains($combined, 'phone_e164');
$internalIdAbsent = is_string($customerId)
    && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $customerId) === 1
    && preg_match('/"id"\s*:\s*-?\d+/', $combined) !== 1;
$tokenOk = is_string($token) && $token !== '';
$requestIdOk = is_string($requestId) && $requestId !== '' && is_string($verifyId) && $verifyId !== '';
$retryOk = $retryAfter === 60;

echo 'request_status='.$requestStatus."\n";
echo 'verify_status='.$verifyStatus."\n";
echo 'retry_after_present='.($retryOk ? 'yes' : 'no')."\n";
echo 'phone_masked_present='.($maskedOk ? 'yes' : 'no')."\n";
echo 'raw_phone_absent='.($rawAbsent ? 'yes' : 'no')."\n";
echo 'access_token_present='.($tokenOk ? 'yes' : 'no')."\n";
echo 'internal_id_absent='.($internalIdAbsent ? 'yes' : 'no')."\n";
echo 'phone_fields_absent='.($phoneFieldsAbsent ? 'yes' : 'no')."\n";
echo 'request_id_present='.($requestIdOk ? 'yes' : 'no')."\n";
echo 'json_valid='.($requestJsonValid && $verifyJsonValid ? 'yes' : 'no')."\n";

if (
    $requestStatus !== 202
    || $verifyStatus !== 200
    || ! $retryOk
    || ! $maskedOk
    || ! $rawAbsent
    || ! $phoneFieldsAbsent
    || ! $internalIdAbsent
    || ! $tokenOk
    || ! $requestIdOk
    || ! $requestJsonValid
    || ! $verifyJsonValid
) {
    exit(1);
}
