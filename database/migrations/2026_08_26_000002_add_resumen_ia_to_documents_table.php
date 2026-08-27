<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resumen por documento, para que la ficha del cliente deje de depender de
 * cuánto texto crudo cabe en un prompt.
 *
 * Medido con clientes reales: con el texto completo, la ficha de MELENDEZ solo
 * alcanzaba a leer 12 de sus 96 documentos, y la de ELIAS ACOSTA 12 de 147. Se
 * quedaba sin presupuesto de caracteres mucho antes que sin documentos. Con un
 * resumen de ~700 caracteres por documento, los 148 de ELIAS ocupan ~104.000 y
 * caben enteros: la ficha pasa a conocer el 100% del material.
 *
 * `resumen_ia_at` sigue la misma convención que `texto_extraido_at`: se compara
 * contra `updated_at` para saber si el resumen quedó obsoleto, y se escribe en
 * el MISMO save que el resumen para que no quede por detrás.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->text('resumen_ia')->nullable()->after('texto_extraido_at');
            $table->timestamp('resumen_ia_at')->nullable()->after('resumen_ia');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['resumen_ia', 'resumen_ia_at']);
        });
    }
};
