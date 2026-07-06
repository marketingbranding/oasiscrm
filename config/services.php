<?php

return [

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google_script' => [
        'webhook_url' => env('GOOGLE_SCRIPT_WEBHOOK_URL'),
        'timeout' => env('GOOGLE_SCRIPT_TIMEOUT', 30),
        'stage_timeout' => env('GOOGLE_SCRIPT_STAGE_TIMEOUT', 12),
        'verify_ssl' => env('GOOGLE_SCRIPT_VERIFY_SSL', false),
    ],

    'google_sheets' => [
        'credentials_path' => env('GOOGLE_SHEETS_CREDENTIALS_PATH', storage_path('app/google/service-account.json')),
        'cache_stale_minutes' => env('GOOGLE_SHEETS_CACHE_STALE_MINUTES', 30),
        'verify_ssl' => env('GOOGLE_SHEETS_VERIFY_SSL', true),
    ],

];
