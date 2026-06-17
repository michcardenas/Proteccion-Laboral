<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Plantillas de tarjetas del tablero Kanban por tipo de servicio.
     * Derivan de la "rúbrica" del contrato (Cláusula de Alcance): cada actividad
     * pactada se convierte en una tarjeta que se autogenera al crear el proceso.
     */
    public function up(): void
    {
        Schema::create('service_task_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_type_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('orden')->default(0);
            $table->string('titulo', 200);
            $table->text('descripcion')->nullable();
            $table->enum('prioridad', ['baja', 'media', 'alta', 'urgente'])->default('media');
            // Días desde la apertura del proceso para fijar fecha_limite de la tarea.
            $table->unsignedInteger('sla_dias')->nullable();
            $table->boolean('es_activo')->default(true);
            $table->timestamps();

            $table->index(['service_type_id', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_task_templates');
    }
};
