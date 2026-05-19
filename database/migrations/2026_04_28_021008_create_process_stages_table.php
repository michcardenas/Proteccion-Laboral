<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('process_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('process_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_stage_template_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('orden')->default(0);
            $table->string('nombre', 160);
            $table->text('descripcion')->nullable();
            $table->enum('estado', ['pendiente', 'en_curso', 'bloqueada', 'completada'])->default('pendiente');
            $table->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_limite')->nullable();
            $table->timestamp('fecha_completada')->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();

            $table->index(['process_id', 'orden']);
            $table->index(['process_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('process_stages');
    }
};
