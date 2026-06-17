<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivote tarea <-> correo ingestado. Permite adjuntar a una tarjeta del Kanban
 * los correos del proceso, para dar contexto a quien la ejecuta. Un mismo correo
 * puede adjuntarse a varias tarjetas y sigue perteneciendo al proceso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_email_ingestion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_ingestion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attached_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['task_id', 'email_ingestion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_email_ingestion');
    }
};
