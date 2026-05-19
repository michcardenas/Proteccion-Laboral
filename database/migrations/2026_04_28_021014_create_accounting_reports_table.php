<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_reports', function (Blueprint $table) {
            $table->id();
            $table->string('periodo', 20)->comment('YYYY-MM o YYYY-Q1, etc.');
            $table->string('tipo', 60)->nullable();
            $table->string('archivo', 500);
            $table->foreignId('subido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notas')->nullable();
            $table->timestamps();

            $table->index('periodo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_reports');
    }
};
