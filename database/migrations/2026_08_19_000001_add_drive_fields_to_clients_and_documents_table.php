<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enlaza a los clientes con su carpeta de la unidad compartida de Drive y a los
 * documentos con el archivo del que provienen, para que la sincronización sea
 * idempotente (se re-descarga solo lo que cambió en Drive).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('drive_folder_id')->nullable()->index()->after('resumen_documental_at');
            $table->string('drive_folder_name')->nullable()->after('drive_folder_id');
            $table->timestamp('drive_synced_at')->nullable()->after('drive_folder_name');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->string('drive_file_id')->nullable()->index()->after('texto_extraido_at');
            $table->timestamp('drive_modified_at')->nullable()->after('drive_file_id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['drive_folder_id', 'drive_folder_name', 'drive_synced_at']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['drive_file_id', 'drive_modified_at']);
        });
    }
};
