<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('process_id')->constrained()->cascadeOnDelete();
            // Denormalizado para consultas directas del portal del cliente.
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            // Abogado que registra la visita.
            $table->foreignId('registrada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('tipo', ['presencial', 'virtual', 'telefonica', 'otro'])->default('presencial');
            $table->date('fecha');
            $table->string('titulo', 200);
            $table->text('descripcion')->nullable();
            // Si el cliente puede verla en su portal (las notas internas pueden ocultarse).
            $table->boolean('visible_cliente')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['process_id', 'fecha']);
            $table->index(['client_id', 'visible_cliente']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
