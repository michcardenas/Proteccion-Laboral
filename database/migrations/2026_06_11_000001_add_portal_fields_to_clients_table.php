<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Acceso al portal del cliente: entra con su NIT + esta contraseña.
            $table->string('password')->nullable()->after('email');
            $table->boolean('portal_activo')->default(false)->after('password');
            $table->timestamp('portal_last_login_at')->nullable()->after('portal_activo');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['password', 'portal_activo', 'portal_last_login_at']);
        });
    }
};
