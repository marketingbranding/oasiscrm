<?php

return [
    'enabled' => env('AI_CHAT_ENABLED', true),
    'timeout' => (int) env('AI_CHAT_TIMEOUT', 30),
    'max_messages' => (int) env('AI_CHAT_MAX_MESSAGES', 12),
    'max_context_messages' => (int) env('AI_CHAT_MAX_CONTEXT_MESSAGES', env('AI_CHAT_MAX_MESSAGES', 12)),
    'max_stored_messages' => (int) env('AI_CHAT_MAX_STORED_MESSAGES', 50),
    'max_input_length' => (int) env('AI_CHAT_MAX_INPUT_LENGTH', 1000),
    'sync_stale_minutes' => (int) env('AI_SYNC_STALE_MINUTES', 5),
    'routing_mode' => env('AI_CHAT_ROUTING_MODE', 'hybrid'),
    'synthesize_tool_results' => env('AI_CHAT_SYNTHESIZE_TOOL_RESULTS', false),
    'max_tool_calls' => (int) env('AI_CHAT_MAX_TOOL_CALLS', 3),
    'connect_timeout' => (int) env('AI_CHAT_CONNECT_TIMEOUT', 5),

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
