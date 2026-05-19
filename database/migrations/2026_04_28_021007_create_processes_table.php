<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('abogado_lider_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('apoderado_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('coordinador_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('codigo', 40)->unique();
            $table->string('titulo', 200);
            $table->text('descripcion')->nullable();
            $table->enum('estado', ['abierto', 'en_curso', 'en_revision', 'cerrado', 'archivado'])->default('abierto');
            $table->date('fecha_apertura');
            $table->date('fecha_cierre')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'estado']);
            $table->index('abogado_lider_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processes');
    }
};
