<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pagos que el cliente realiza, registrados por el abogado dentro de un proceso.
 * Sirve de constancia tanto para el equipo como para el cliente (portal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('process_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('monto', 14, 2);
            $table->date('fecha_pago');
            $table->string('concepto', 200);
            $table->enum('metodo', ['efectivo', 'transferencia', 'consignacion', 'tarjeta', 'cheque', 'otro'])
                ->default('transferencia');
            $table->string('referencia', 120)->nullable(); // n° de transacción/consignación
            $table->text('notas')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['process_id', 'fecha_pago']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
