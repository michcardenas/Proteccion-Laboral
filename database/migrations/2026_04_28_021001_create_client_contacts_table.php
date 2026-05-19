<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('nombre', 150);
            $table->string('cargo', 120)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->boolean('es_principal')->default(false);
            $table->text('notas')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'es_principal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_contacts');
    }
};
