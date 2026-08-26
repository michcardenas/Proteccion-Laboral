<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Unidad compartida de Google Drive (base documental del despacho)
    |--------------------------------------------------------------------------
    |
    | Reusa las credenciales OAuth de Gmail (mismo proyecto de Google Cloud y misma
    | cuenta conectada); lo único que cambia es el scope `drive.readonly`, declarado
    | en config/gmail.php. Tras añadirlo hay que RECONECTAR la cuenta desde el wizard
    | (/admin/integrations/gmail) para que Google emita un token con ese permiso.
    |
    */

    // Id de la unidad compartida. Si queda vacío se usa la primera que vea la cuenta.
    'shared_drive_id' => env('DRIVE_SHARED_DRIVE_ID'),

    // Carpeta raíz dentro de la unidad donde viven las carpetas por cliente.
    // Vacío = la raíz de la unidad compartida.
    'root_folder_id' => env('DRIVE_ROOT_FOLDER_ID'),

    // Guardar el binario en disco además del texto extraído. Con `false` solo se
    // conserva el texto (menos disco) y el documento queda como enlace a Drive.
    'store_files' => (bool) env('DRIVE_STORE_FILES', true),

    // Archivos más grandes que esto se omiten (no se descargan ni se leen).
    'max_file_mb' => (int) env('DRIVE_MAX_FILE_MB', 25),

    // Máximo de archivos a sincronizar por cliente en una corrida.
    'max_files_per_client' => (int) env('DRIVE_MAX_FILES_PER_CLIENT', 200),

    // Profundidad máxima de subcarpetas al recorrer la carpeta de un cliente.
    'max_depth' => (int) env('DRIVE_MAX_DEPTH', 5),

    // Marca como eliminados los documentos cuyo archivo ya no está en Drive.
    'prune' => (bool) env('DRIVE_PRUNE', true),

    // Sincronización automática diaria (apagada por defecto: regenerar las fichas
    // de conocimiento consume API de Anthropic).
    'auto_sync' => (bool) env('DRIVE_AUTO_SYNC', false),
    'auto_sync_at' => env('DRIVE_AUTO_SYNC_AT', '02:00'),

];
