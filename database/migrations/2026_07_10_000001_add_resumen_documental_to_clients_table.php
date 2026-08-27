<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ficha de conocimiento del cliente: un digest generado por IA que resume TODOS los
 * documentos del cliente en un texto compacto. Se inyecta siempre en el contexto de la
 * IA (amplitud), complementando la inyección de texto crudo acotada (profundidad).
 * Mismo patrón que `processes.resumen_ia`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->longText('resumen_documental')->nullable()->after('notas');
            $table->timestamp('resumen_documental_at')->nullable()->after('resumen_documental');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['resumen_documental', 'resumen_documental_at']);
        });
    }
};
