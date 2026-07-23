<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_access_tokens')) {
            return;
        }

        Schema::create('user_access_tokens', function (Blueprint $table): void {
            $table->bigIncrements('token_id');
            $table->unsignedBigInteger('user_id');
            $table->char('token_hash', 64)->unique();
            $table->string('user_agent', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table
                ->foreign('user_id')
                ->references('user_id')
                ->on('system_users')
                ->cascadeOnDelete();
            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_access_tokens');
    }
};
