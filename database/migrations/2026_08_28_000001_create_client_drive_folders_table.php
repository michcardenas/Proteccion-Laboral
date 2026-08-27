<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carpetas de Drive adicionales de un cliente.
 *
 * `clients.drive_folder_id` da por hecho que cada empresa vive en una sola
 * carpeta, y en el despacho no es así: una misma empresa atendida por dos
 * abogadas tiene carpeta bajo cada una (SUMITOR, DOS MOLINOS, ZONA MEDICA).
 *
 * Con un solo campo, mapear la segunda pisaba la primera y sus documentos se
 * volvían invisibles para la IA sin que nada avisara. Aquí se guardan las
 * demás: la principal sigue en `clients` y el sync recorre todas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_drive_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('drive_folder_id', 120);
            $table->string('drive_folder_name', 300)->nullable();
            $table->timestamps();

            // Una carpeta pertenece a un solo cliente: si se mapea dos veces,
            // es un error de mapeo, no dos vínculos.
            $table->unique('drive_folder_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_drive_folders');
    }
};
