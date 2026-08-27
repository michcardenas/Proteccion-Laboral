<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De qué cuenta de Gmail entró cada correo.
 *
 * Con una sola bandeja compartida la pregunta no existía. Con una cuenta por
 * abogada sí: es lo que decide quién puede ver el correo —cada una la suya, el
 * director todas— y desde qué cuenta sale la respuesta.
 *
 * Queda nullable a propósito. Los correos que ya entraron por la cuenta de
 * automatización no tienen a quién atribuirse, y esos los ve solo el director.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_ingestions', function (Blueprint $table) {
            $table->foreignId('integration_token_id')
                ->nullable()
                ->after('to')
                ->constrained('integration_tokens')
                ->nullOnDelete();

            // Se filtra por esto en cada listado de la bandeja.
            $table->index('integration_token_id');
        });
    }

    public function down(): void
    {
        Schema::table('email_ingestions', function (Blueprint $table) {
            $table->dropForeign(['integration_token_id']);
            $table->dropIndex(['integration_token_id']);
            $table->dropColumn('integration_token_id');
        });
    }
};
