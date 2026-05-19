<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('process_stage_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checklist_item_id')->nullable()->constrained('service_checklist_items')->nullOnDelete();
            $table->text('descripcion');
            $table->boolean('es_obligatorio')->default(true);
            $table->boolean('completado')->default(false);
            $table->foreignId('completado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completado_at')->nullable();
            $table->text('observacion')->nullable();
            $table->timestamps();

            $table->index(['process_stage_id', 'completado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_responses');
    }
};
