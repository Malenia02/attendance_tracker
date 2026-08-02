<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEXES = [
        'attendance_records' => [
            'idx_action_attendance_verify' => ['is_verified', 'attendance_date', 'personnel_id'],
            'idx_action_morning_timeout' => ['attendance_date', 'morning_time_out', 'personnel_id'],
            'idx_action_afternoon_timeout' => ['attendance_date', 'afternoon_time_out', 'personnel_id'],
        ],
        'attendance_correction_requests' => [
            'idx_action_correction_pending' => ['request_status', 'personnel_id', 'created_at'],
        ],
        'leave_records' => [
            'idx_action_leave_pending' => ['approval_status', 'personnel_id', 'created_at'],
        ],
        'dtr_certifications' => [
            'idx_action_dtr_returned' => ['certification_status', 'personnel_id', 'updated_at'],
        ],
        'personnel' => [
            'idx_action_qr_expiry' => ['status', 'qr_valid_until', 'personnel_id'],
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

                Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName): void {
                    $table->index($columns, $indexName);
                });
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

                Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                    $table->dropIndex($indexName);
                });
            }
        }
    }
};
