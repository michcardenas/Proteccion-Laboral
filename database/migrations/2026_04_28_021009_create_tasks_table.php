<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('process_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('process_stage_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('titulo', 200);
            $table->text('descripcion')->nullable();
            $table->foreignId('asignado_a')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('prioridad', ['baja', 'media', 'alta', 'urgente'])->default('media');
            $table->enum('estado', ['pendiente', 'en_curso', 'bloqueada', 'completada', 'cancelada'])->default('pendiente');
            $table->date('fecha_limite')->nullable();
            $table->timestamp('completada_at')->nullable();
            $table->timestamps();

            $table->index(['asignado_a', 'estado']);
            $table->index(['process_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
