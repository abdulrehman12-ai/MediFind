<?php

return [
    'business_timezone' => env('BUSINESS_TIMEZONE', 'Asia/Karachi'),

    'otp' => [
        'provider' => env('SMS_OTP_PROVIDER', 'local'),
        'hmac_key' => env('OTP_HMAC_KEY'),
        'local_code' => env('LOCAL_OTP_CODE'),
        'ttl_seconds' => 300,
        'cooldown_seconds' => 60,
        'max_attempts' => 5,
        'phone_send_limit' => 5,
        'ip_attempt_limit' => 20,
        'public_ip_limit' => 120,
        'public_window_seconds' => 60,
        'window_seconds' => 3600,
        'window_ms' => 3600000,
    ],
];
