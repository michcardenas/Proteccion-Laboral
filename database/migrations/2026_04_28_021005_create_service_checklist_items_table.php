<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_type_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('service_stage_template_id')->nullable()->constrained('service_stage_templates')->cascadeOnDelete();
            $table->text('descripcion');
            $table->boolean('es_obligatorio')->default(true);
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();

            $table->index('service_type_id');
            $table->index('service_stage_template_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_checklist_items');
    }
};
