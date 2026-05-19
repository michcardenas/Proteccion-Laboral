<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_stage_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_type_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('orden')->default(0);
            $table->string('nombre', 160);
            $table->text('descripcion')->nullable();
            $table->string('rol_responsable_default', 40)->nullable();
            $table->unsignedInteger('sla_dias')->nullable();
            $table->timestamps();

            $table->index(['service_type_id', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_stage_templates');
    }
};
