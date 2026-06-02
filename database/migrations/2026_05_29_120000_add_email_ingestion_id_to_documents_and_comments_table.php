<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('email_ingestion_id')
                ->nullable()
                ->after('process_id')
                ->constrained()
                ->nullOnDelete();
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->foreignId('email_ingestion_id')
                ->nullable()
                ->after('user_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('email_ingestion_id');
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('email_ingestion_id');
        });
    }
};
