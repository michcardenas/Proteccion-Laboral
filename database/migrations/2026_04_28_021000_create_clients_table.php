<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('razon_social', 200);
            $table->string('nit', 30)->nullable()->unique();
            $table->string('dv', 2)->nullable();
            $table->string('ciudad', 80)->nullable();
            $table->string('sector', 100)->nullable();
            $table->string('contacto_principal', 150)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->date('fecha_alta')->nullable();
            $table->enum('estado', ['activo', 'pausado', 'inactivo', 'prospecto'])->default('activo');
            $table->text('notas')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['estado']);
            $table->index(['razon_social']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
