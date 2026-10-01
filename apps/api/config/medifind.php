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

    'discovery' => [
        'default_per_page' => 20,
        'max_per_page' => 50,
        'max_offset' => 500,
        'default_radius_meters' => 10000,
        'max_radius_meters' => 50000,
        'freshness_hours' => 24,
        'min_query_length' => 2,
        'max_query_length' => 80,
        'max_medicines' => 10,
    ],

    'prescriptions' => [
        'disk' => env('PRESCRIPTION_DISK', 'prescriptions'),
        'pdf_worker_url' => env('PDF_WORKER_URL', ''),
        'pdf_worker_token' => env('PDF_WORKER_TOKEN', ''),
        'extractor' => env('PRESCRIPTION_EXTRACTOR', 'unconfigured'),
        'retention_days' => ($retention = env('PRESCRIPTION_RETENTION_DAYS')) === null || $retention === '' ? null : (int) $retention,
        'claim_lease_seconds' => 90,
        'orphan_grace_seconds' => 900,
        'prune_attempt_limit' => 2000,
        'claim_recover_limit' => 200,
        'testing_hooks' => [
            'after_object_put' => null,
            'after_claim' => null,
            'before_replace_save' => null,
            'before_hold_claim' => null,
            'after_hold_claim' => null,
            'after_hold_delete' => null,
        ],
        'upload_limit' => 20,
        'edit_limit' => 60,
        'share_limit' => 30,
        'read_limit' => 120,
        'fixtures' => [],
    ],

    'orders' => [
        'max_quantity' => 99,
        'max_note_length' => 500,
        'max_cancel_reason_length' => 200,
        'history_page_size' => 20,
        'quote_ttl_seconds' => 86400,
        'pickup_grace_seconds' => 7200,
        'testing_hooks' => [
            'before_submit_commit' => null,
        ],
    ],
];
