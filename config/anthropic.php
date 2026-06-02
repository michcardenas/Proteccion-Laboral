<?php

return [
    'api_key' => env('ANTHROPIC_API_KEY'),
    'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
    'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 4096),
    'timeout' => (int) env('ANTHROPIC_TIMEOUT', 60),
    'base_url' => 'https://api.anthropic.com/v1',
    'anthropic_version' => '2023-06-01',
];
