<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las cabeceras de un correo no caben en 255 caracteres.
 *
 * `to` era varchar(255) y un correo con muchos destinatarios lo desborda. El
 * sondeo fallaba al guardarlo, el cron lo reintentaba dos minutos despues y
 * volvia a fallar: seiscientas ochenta y ocho veces en un dia, con ese correo
 * sin entrar nunca y sin mas rastro que un log que nadie lee.
 *
 * `to` pasa a text porque una lista de destinatarios no tiene techo razonable.
 * `from` y `subject` se quedan acotados —uno es un remitente y el otro un
 * asunto— pero con holgura, y ademas se recortan al escribir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_ingestions', function (Blueprint $table) {
            $table->text('to')->change();
            $table->string('from', 500)->change();
            $table->string('subject', 1000)->change();
        });
    }

    public function down(): void
    {
        Schema::table('email_ingestions', function (Blueprint $table) {
            $table->string('to')->change();
            $table->string('from')->change();
            $table->string('subject')->change();
        });
    }
};
