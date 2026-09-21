<?php

return [
    'api_key' => env('ANTHROPIC_API_KEY'),
    'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5'),

    // 4.096 se quedaba corto: los borradores jurídicos agotaban el tope y
    // salían cortados a media frase (era la causa de los 504 del modal). Y en
    // Sonnet 5 el razonamiento va ENCENDIDO por defecto y se descuenta de este
    // mismo tope, así que con 4.096 quedaría todavía menos texto visible.
    'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 12000),
    'timeout' => (int) env('ANTHROPIC_TIMEOUT', 60),
    'base_url' => 'https://api.anthropic.com/v1',
    'anthropic_version' => '2023-06-01',
];
