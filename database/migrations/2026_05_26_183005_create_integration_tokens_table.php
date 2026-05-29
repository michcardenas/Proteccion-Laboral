<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('account_email');
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('expires_at')->nullable();
            $table->json('scopes');
            $table->foreignId('connected_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['provider', 'account_email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_tokens');
    }
};
