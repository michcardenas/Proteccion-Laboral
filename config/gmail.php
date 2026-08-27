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

    /*
     * `consent` a secas fuerza la pantalla de permisos pero NO la de elegir
     * cuenta: si el navegador ya tiene sesión con una cuenta de Google, la
     * reautoriza esa misma sin preguntar. En producción quedó conectado un
     * Gmail personal por eso — la pantalla no daba forma de cambiarlo, y
     * desconectar y volver a conectar reconectaba la misma cuenta.
     *
     * Con `select_account` delante, Google muestra siempre el selector. Es un
     * clic de más para quien ya tiene la cuenta correcta, y la única forma de
     * cambiarla para quien no.
     */
    'prompt' => 'select_account consent',
];
