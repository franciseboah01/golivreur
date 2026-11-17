<?php

return [
    'hub2' => [
        'api_key' => env('HUB2_API_KEY', ''),
        'merchant_id' => env('HUB2_MERCHANT_ID', ''),
        'webhook_secret' => env('HUB2_WEBHOOK_SECRET', 'test-hub2-webhook-secret'),
    ],

    'cinetpay' => [
        'api_key' => env('CINETPAY_API_KEY', ''),
        'site_id' => env('CINETPAY_SITE_ID', ''),
        'secret_key' => env('CINETPAY_SECRET_KEY', ''),
    ],

    'fcm' => [
        'credentials_path' => env('FCM_CREDENTIALS_PATH', ''),
    ],

    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
    ],

    'yellika' => [
        'base_url' => env('YELLIKA_BASE_URL', 'https://panel.yellikasms.com/api/v3/sms/send'),
        'api_token' => env('YELLIKA_API_TOKEN', ''),
        'sender_id' => env('YELLIKA_SENDER_ID', 'GoLivreur'),
    ],

    'admin_alert_email' => env('ADMIN_ALERT_EMAIL', 'admin@golivreur.ci'),

    'health' => [
        'token' => env('HEALTH_CHECK_TOKEN', 'golivreur-secret-health-token'),
    ],
];
