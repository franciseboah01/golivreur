<?php

return [
    'cinetpay' => [
        'api_key' => env('CINETPAY_API_KEY', ''),
        'site_id' => env('CINETPAY_SITE_ID', ''),
        'secret_key' => env('CINETPAY_SECRET_KEY', ''),
    ],

    'fcm' => [
        'credentials_path' => env('FCM_CREDENTIALS_PATH', ''),
    ],

    'health' => [
        'token' => env('HEALTH_CHECK_TOKEN', 'edulink-secret-health-token'),
    ],
];

