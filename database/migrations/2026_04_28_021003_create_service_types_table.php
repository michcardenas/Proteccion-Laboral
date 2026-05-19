<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_types', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 160);
            $table->string('slug', 180)->unique();
            $table->text('descripcion')->nullable();
            $table->enum('modalidad', [
                'permanente',
                'por_evento',
                'judicial',
                'estrategico',
                'capacitacion',
                'prediagnostico',
            ]);
            $table->boolean('es_activo')->default(true);
            $table->timestamps();

            $table->index(['modalidad', 'es_activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_types');
    }
};
