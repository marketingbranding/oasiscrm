<?php

return [
    'enabled' => env('AI_CHAT_ENABLED', true),
    'timeout' => (int) env('AI_CHAT_TIMEOUT', 30),
    'max_messages' => (int) env('AI_CHAT_MAX_MESSAGES', 12),
    'max_input_length' => (int) env('AI_CHAT_MAX_INPUT_LENGTH', 1000),

    'primary' => [
        'provider' => env('AI_PRIMARY_PROVIDER', 'openrouter'),
        'base_url' => rtrim((string) env('AI_PRIMARY_BASE_URL', 'https://openrouter.ai/api/v1'), '/'),
        'api_key' => env('AI_PRIMARY_KEY'),
        'model' => env('AI_PRIMARY_MODEL', 'meta-llama/llama-3.1-8b-instruct:free'),
    ],

    'fallback' => [
        'provider' => env('AI_FALLBACK_PROVIDER', 'ollama'),
        'base_url' => rtrim((string) env('AI_FALLBACK_BASE_URL', 'http://localhost:11434/v1'), '/'),
        'api_key' => env('AI_FALLBACK_KEY'),
        'model' => env('AI_FALLBACK_MODEL', 'llama3.1'),
    ],
];
