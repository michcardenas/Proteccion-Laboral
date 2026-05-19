<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('process_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('process_stage_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('nombre', 200);
            $table->string('ruta', 500);
            $table->string('disco', 30)->default('local');
            $table->enum('tipo', ['contrato', 'concepto', 'informe', 'escrito', 'comunicacion', 'soporte', 'otro'])->default('otro');
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('tamano_bytes')->nullable();
            $table->boolean('generado_por_ia')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('subido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('visible_cliente')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['process_id', 'tipo']);
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
