<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('processes', function (Blueprint $table) {
            // Resumen ejecutivo del proceso generado por IA (no del correo ni de las tareas).
            $table->text('resumen_ia')->nullable()->after('descripcion');
            $table->timestamp('resumen_ia_generado_at')->nullable()->after('resumen_ia');
        });
    }

    public function down(): void
    {
        Schema::table('processes', function (Blueprint $table) {
            $table->dropColumn(['resumen_ia', 'resumen_ia_generado_at']);
        });
    }
};
