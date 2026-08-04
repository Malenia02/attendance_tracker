<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('personnel_activation_logs')) {
            Schema::create('personnel_activation_logs', function (Blueprint $table): void {
                $table->id('personnel_activation_log_id');
                $table->unsignedBigInteger('personnel_id');
                $table->unsignedBigInteger('changed_by')->nullable();
                $table->string('from_status', 20);
                $table->string('to_status', 20);
                $table->string('reason', 500)->nullable();
                $table->json('readiness_snapshot');
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->uuid('request_id')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('personnel_id', 'fk_activation_personnel')
                    ->references('personnel_id')->on('personnel')
                    ->restrictOnDelete()->cascadeOnUpdate();
                $table->foreign('changed_by', 'fk_activation_user')
                    ->references('user_id')->on('system_users')
                    ->nullOnDelete()->cascadeOnUpdate();
                $table->index(
                    ['personnel_id', 'created_at'],
                    'idx_activation_personnel_history'
                );
                $table->index(
                    ['changed_by', 'created_at'],
                    'idx_activation_actor_history'
                );
                $table->index('request_id', 'idx_activation_request');
            });
        }

        $this->addIndex(
            'personnel',
            ['status', 'employment_start_date', 'employment_end_date', 'personnel_id'],
            'idx_onboarding_employment_state'
        );
        $this->addIndex(
            'personnel',
            ['status', 'qr_valid_from', 'qr_valid_until', 'personnel_id'],
            'idx_onboarding_credential_state'
        );
    }

    public function down(): void
    {
        $this->dropIndex('personnel', 'idx_onboarding_credential_state');
        $this->dropIndex('personnel', 'idx_onboarding_employment_state');
        Schema::dropIfExists('personnel_activation_logs');
    }

    private function addIndex(string $table, array $columns, string $name): void
    {
        if (! Schema::hasTable($table) || Schema::hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
    }

    private function dropIndex(string $table, string $name): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
    }
};
