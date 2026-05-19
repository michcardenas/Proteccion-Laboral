<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('contexto_tipo', 80)->nullable();
            $table->unsignedBigInteger('contexto_id')->nullable();
            $table->string('proveedor', 30)->default('openai');
            $table->string('modelo', 60);
            $table->longText('prompt');
            $table->longText('respuesta')->nullable();
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->decimal('costo_usd', 10, 6)->default(0);
            $table->enum('estado', ['ok', 'error', 'timeout'])->default('ok');
            $table->text('error_mensaje')->nullable();
            $table->timestamps();

            $table->index(['contexto_tipo', 'contexto_id']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_generations');
    }
};
