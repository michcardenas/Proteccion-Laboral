<?php

return [
    'client_id' => env('GMAIL_CLIENT_ID'),
    'client_secret' => env('GMAIL_CLIENT_SECRET'),
    'redirect_uri' => env('GMAIL_REDIRECT_URI'),
    'token_path' => env('GMAIL_TOKEN_PATH', 'storage/app/gmail/token.json'),
    'scopes' => [
        'https://www.googleapis.com/auth/gmail.readonly',
        'https://www.googleapis.com/auth/gmail.modify',
        // Lectura de la unidad compartida del despacho (base documental de los clientes).
        // Al añadir este scope hay que reconectar la cuenta: el token existente NO lo tiene.
        'https://www.googleapis.com/auth/drive.readonly',
    ],
    'access_type' => 'offline',
    'prompt' => 'consent',
];
