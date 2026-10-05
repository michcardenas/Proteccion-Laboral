<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La clave que pone el despacho (por defecto el NIT) es provisional: el
     * cliente la cambia al entrar por primera vez.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('debe_cambiar_clave')->default(false)->after('portal_activo');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('debe_cambiar_clave');
        });
    }
};
