<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_notifications')) {
            return;
        }

        Schema::create('user_notifications', function (Blueprint $table): void {
            $table->id('notification_id');
            $table->unsignedBigInteger('user_id');
            $table->string('notification_key', 191);
            $table->string('notification_type', 80);
            $table->string('title', 160);
            $table->string('message', 500);
            $table->string('severity', 20)->default('Info');
            $table->string('action_url', 255)->nullable();
            $table->char('content_hash', 64);
            $table->json('metadata')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id', 'fk_user_notification_user')
                ->references('user_id')
                ->on('system_users')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->unique(
                ['user_id', 'notification_key'],
                'uq_user_notification_key'
            );
            $table->index(
                ['user_id', 'resolved_at', 'read_at', 'created_at'],
                'idx_notification_inbox'
            );
            $table->index(
                ['user_id', 'notification_type', 'created_at'],
                'idx_notification_user_type'
            );
            $table->index(
                ['resolved_at', 'created_at'],
                'idx_notification_retention'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
