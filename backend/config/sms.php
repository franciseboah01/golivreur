<?php

return [
    'driver' => env('SMS_DRIVER', 'log'),

    'yellika' => [
        'base_url' => env('YELLIKA_BASE_URL', 'https://panel.yellikasms.com/api/v3/sms/send'),
        'api_token' => env('YELLIKA_API_TOKEN', ''),
        'sender_id' => env('YELLIKA_SENDER_ID', 'GoLivreur'),
        'type' => env('YELLIKA_SMS_TYPE', 'plain'),
        'timeout' => (int) env('YELLIKA_TIMEOUT_SECONDS', 10),
        'max_attempts' => 2,
    ],
];
