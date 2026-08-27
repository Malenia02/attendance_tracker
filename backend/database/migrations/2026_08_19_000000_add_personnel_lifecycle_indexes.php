<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndex(
            'personnel',
            ['status', 'employment_end_date', 'personnel_id'],
            'idx_personnel_lifecycle_due'
        );
        $this->addIndex(
            'personnel_activation_logs',
            ['to_status', 'created_at', 'personnel_id'],
            'idx_activation_lifecycle_status'
        );
    }

    public function down(): void
    {
        $this->dropIndex('personnel_activation_logs', 'idx_activation_lifecycle_status');
        $this->dropIndex('personnel', 'idx_personnel_lifecycle_due');
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
