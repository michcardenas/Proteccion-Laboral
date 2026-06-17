<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Asistentes a la visita (abogados/equipo que asistieron).
        Schema::create('visit_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['visit_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_user');
    }
};
