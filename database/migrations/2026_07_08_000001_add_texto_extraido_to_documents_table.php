<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cachea el texto extraído de cada documento/adjunto para que la IA pueda leer
 * su contenido sin re-extraerlo en cada generación (la extracción de PDF/DOCX es CPU).
 * `texto_extraido_at` marca que YA se intentó (aunque el resultado sea vacío/no soportado),
 * para no reintentar formatos que no se pueden leer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->longText('texto_extraido')->nullable()->after('tamano_bytes');
            $table->timestamp('texto_extraido_at')->nullable()->after('texto_extraido');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['texto_extraido', 'texto_extraido_at']);
        });
    }
};
