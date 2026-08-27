<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una carpeta de Drive adicional de un cliente.
 *
 * La principal vive en `clients.drive_folder_id`; aquí van las demás, que
 * aparecen cuando dos abogadas llevan a la misma empresa y cada una tiene su
 * propia carpeta.
 */
class ClientDriveFolder extends Model
{
    protected $fillable = ['client_id', 'drive_folder_id', 'drive_folder_name'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
