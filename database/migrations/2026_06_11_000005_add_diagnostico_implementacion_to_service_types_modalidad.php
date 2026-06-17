<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Añade la modalidad 'diagnostico_implementacion' al enum de service_types.
     * Corresponde a la "Hoja de Ruta: Diagnóstico e Implementación" (plan de
     * trabajo real entregado por el despacho).
     *
     * Driver-aware: en MySQL (XAMPP/producción) se reescribe el ENUM con SQL
     * crudo; en SQLite (tests en memoria) el ENUM se materializa como varchar
     * con un CHECK, así que se relaja a string para aceptar el nuevo valor sin
     * romper las migraciones de la suite.
     */
    private const MODALIDADES = [
        'permanente',
        'por_evento',
        'judicial',
        'estrategico',
        'capacitacion',
        'prediagnostico',
        'diagnostico_implementacion',
    ];

    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $values = "'".implode("','", self::MODALIDADES)."'";
            DB::statement("ALTER TABLE service_types MODIFY modalidad ENUM({$values}) NOT NULL");

            return;
        }

        // SQLite y otros: quitar el CHECK del enum original pasando a string.
        Schema::table('service_types', function (Blueprint $table) {
            $table->string('modalidad', 40)->change();
        });
    }

    public function down(): void
    {
        // Reasigna registros con la nueva modalidad antes de removerla del enum.
        DB::table('service_types')
            ->where('modalidad', 'diagnostico_implementacion')
            ->update(['modalidad' => 'estrategico']);

        $original = array_diff(self::MODALIDADES, ['diagnostico_implementacion']);

        if (DB::getDriverName() === 'mysql') {
            $values = "'".implode("','", $original)."'";
            DB::statement("ALTER TABLE service_types MODIFY modalidad ENUM({$values}) NOT NULL");

            return;
        }

        Schema::table('service_types', function (Blueprint $table) use ($original) {
            $table->enum('modalidad', array_values($original))->change();
        });
    }
};
