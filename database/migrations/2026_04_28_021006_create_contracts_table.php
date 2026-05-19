<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_type_id')->constrained()->restrictOnDelete();
            $table->string('codigo', 40)->unique();
            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();
            $table->decimal('valor', 14, 2)->nullable();
            $table->enum('modalidad_pago', ['mensual', 'unico', 'por_etapa', 'por_hora'])->default('mensual');
            $table->enum('estado', ['borrador', 'activo', 'pausado', 'finalizado', 'cancelado'])->default('borrador');
            $table->text('notas')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
