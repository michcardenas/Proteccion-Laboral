<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de cuándo se respondió un correo entrante (desde la app). Permite
 * mostrar el estado "Respondido / Pendiente" en la bandeja del tablero Kanban.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_ingestions', function (Blueprint $table) {
            $table->timestamp('respondido_at')->nullable()->after('processed_at');
        });
    }

    public function down(): void
    {
        Schema::table('email_ingestions', function (Blueprint $table) {
            $table->dropColumn('respondido_at');
        });
    }
};
