<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Añade el estado 'descartado' al enum de email_ingestions.status.
     * Lo usa la bandeja de Revisión de correos: un correo en `needs_review`
     * que el director/coordinador marca como irrelevante pasa a `descartado`,
     * distinguible de los enrutados (`processed`) para auditoría.
     *
     * Driver-aware: en MySQL (XAMPP/producción) se reescribe el ENUM con SQL
     * crudo; en SQLite (tests en memoria) el ENUM se materializa como varchar
     * con un CHECK, así que se relaja a string para aceptar el nuevo valor sin
     * romper las migraciones de la suite.
     */
    private const STATUSES = [
        'pending',
        'classified',
        'processed',
        'needs_review',
        'failed',
        'descartado',
    ];

    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $values = "'".implode("','", self::STATUSES)."'";
            DB::statement("ALTER TABLE email_ingestions MODIFY status ENUM({$values}) NOT NULL DEFAULT 'pending'");

            return;
        }

        // SQLite y otros: quitar el CHECK del enum original pasando a string.
        Schema::table('email_ingestions', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->change();
        });
    }

    public function down(): void
    {
        // Reasigna registros descartados antes de removerlos del enum.
        DB::table('email_ingestions')
            ->where('status', 'descartado')
            ->update(['status' => 'processed']);

        $original = array_values(array_diff(self::STATUSES, ['descartado']));

        if (DB::getDriverName() === 'mysql') {
            $values = "'".implode("','", $original)."'";
            DB::statement("ALTER TABLE email_ingestions MODIFY status ENUM({$values}) NOT NULL DEFAULT 'pending'");

            return;
        }

        Schema::table('email_ingestions', function (Blueprint $table) use ($original) {
            $table->enum('status', $original)->default('pending')->change();
        });
    }
};
