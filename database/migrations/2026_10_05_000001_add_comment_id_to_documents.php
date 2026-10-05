<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Los adjuntos de una respuesta de correo cuelgan de su comentario, para
     * enseñarlos junto al mensaje en el portal y compartirlos con el.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('comment_id')->nullable()->after('payment_id')
                ->constrained('comments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('comment_id');
        });
    }
};
