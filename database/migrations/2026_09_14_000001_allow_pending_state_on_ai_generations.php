<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `estado` pasa de enum('ok','error','timeout') a string.
 *
 * La generación de borradores deja de ser síncrona: la fila nace en `pendiente`
 * y el job la cierra en `ok` o `error`. Con el enum, insertar 'pendiente' revienta
 * en MySQL y choca contra el CHECK que Laravel genera en SQLite (donde corre la suite).
 *
 * Se va a string en vez de ampliar el enum a propósito: cada estado nuevo obligaba
 * a una migración de esquema sobre una tabla que ya lleva 1.400 filas en producción.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_generations', function (Blueprint $table) {
            $table->string('estado', 20)->default('ok')->change();
        });
    }

    public function down(): void
    {
        // Las filas en estados nuevos no caben en el enum viejo; se normalizan a 'error'
        // antes de estrechar la columna o el ALTER falla.
        DB::table('ai_generations')->whereNotIn('estado', ['ok', 'error', 'timeout'])->update(['estado' => 'error']);

        Schema::table('ai_generations', function (Blueprint $table) {
            $table->enum('estado', ['ok', 'error', 'timeout'])->default('ok')->change();
        });
    }
};
