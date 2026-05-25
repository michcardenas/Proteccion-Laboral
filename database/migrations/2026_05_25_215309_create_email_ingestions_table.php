<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_ingestions', function (Blueprint $table) {
            $table->id();
            $table->string('message_id')->unique();
            $table->string('from');
            $table->string('to');
            $table->string('subject');
            $table->timestamp('received_at');
            $table->json('raw_payload');
            $table->text('body_text');
            $table->enum('status', ['pending', 'classified', 'processed', 'needs_review', 'failed'])
                ->default('pending');
            $table->foreignId('process_id')->nullable()->constrained('processes')->nullOnDelete();
            $table->json('ai_classification')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_ingestions');
    }
};
