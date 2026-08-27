<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La carpeta de Drive a la que corresponde cada proceso.
 *
 * Es lo que permite que el contexto se mantenga solo: cuando alguien suelta un
 * archivo en «16 COMPRAVENTA GARCES», la sincronización mira este campo y ata
 * el documento a ese proceso, en vez de dejarlo colgando del cliente.
 *
 * Se guarda el NOMBRE de la carpeta y no su id de Drive a propósito: el
 * documento trae su ruta dentro de `documents.nombre` («16 COMPRAVENTA GARCES/
 * ESCRITURA…/1.jpeg»), que es lo que hay para emparejar sin una llamada extra
 * a Google por cada archivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('processes', function (Blueprint $table) {
            $table->string('drive_folder')->nullable()->after('titulo');
            $table->index(['client_id', 'drive_folder']);
        });
    }

    public function down(): void
    {
        Schema::table('processes', function (Blueprint $table) {
            $table->dropIndex(['client_id', 'drive_folder']);
            $table->dropColumn('drive_folder');
        });
    }
};
