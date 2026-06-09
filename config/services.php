<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Google Picker (adjuntar documentos de Drive en el Kanban).
    // Valores públicos (van al frontend): API key de navegador, OAuth Client ID (Web)
    // y el número de proyecto (app id). Habilitar "Google Picker API" en Google Cloud.
    'google' => [
        'picker_client_id' => env('GOOGLE_PICKER_CLIENT_ID'),
        'picker_api_key' => env('GOOGLE_PICKER_API_KEY'),
        'picker_app_id' => env('GOOGLE_PICKER_APP_ID'),
        'picker_scope' => env('GOOGLE_PICKER_SCOPE', 'https://www.googleapis.com/auth/drive.file'),
    ],

];
