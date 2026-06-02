<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_generations', function (Blueprint $table) {
            $table->string('request_hash', 64)->nullable()->after('modelo')->index();
            $table->unsignedInteger('latencia_ms')->nullable()->after('tokens_out');
        });
    }

    public function down(): void
    {
        Schema::table('ai_generations', function (Blueprint $table) {
            $table->dropIndex(['request_hash']);
            $table->dropColumn(['request_hash', 'latencia_ms']);
        });
    }
};
