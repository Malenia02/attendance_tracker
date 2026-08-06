<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEXES = [
        'personnel' => [
            'idx_dtr_cutoff_personnel' => ['personnel_type', 'status', 'department_id', 'personnel_id'],
        ],
        'personnel_schedules' => [
            'idx_dtr_schedule_coverage' => ['personnel_id', 'effective_from', 'effective_to', 'schedule_id'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $tableName => $indexes) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            foreach ($indexes as $indexName => $columns) {
                if (Schema::hasIndex($tableName, $indexName)) {
                    continue;
                }

                Schema::table($tableName, fn (Blueprint $table) => $table
                    ->index($columns, $indexName));
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::INDEXES, true) as $tableName => $indexes) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            foreach (array_reverse($indexes, true) as $indexName => $columns) {
                if (! Schema::hasIndex($tableName, $indexName)) {
                    continue;
                }

                Schema::table($tableName, fn (Blueprint $table) => $table
                    ->dropIndex($indexName));
            }
        }
    }
};
