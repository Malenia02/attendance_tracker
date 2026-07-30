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
            ['status', 'department_id', 'last_name', 'first_name'],
            'idx_personnel_scope_name'
        );
        $this->addIndex(
            'dtr_certifications',
            ['dtr_year', 'dtr_month', 'certification_status', 'personnel_id'],
            'idx_dtr_month_status_personnel'
        );
        $this->addIndex(
            'attendance_records',
            ['attendance_date', 'attendance_status', 'personnel_id'],
            'idx_attendance_date_status_personnel'
        );
        $this->addIndex(
            'attendance_records',
            ['attendance_date', 'late_minutes', 'personnel_id'],
            'idx_attendance_date_late_personnel'
        );
        $this->addIndex(
            'qr_scan_logs',
            ['scanned_at', 'personnel_id'],
            'idx_qr_scan_datetime_personnel'
        );
    }

    public function down(): void
    {
        $this->dropIndex('qr_scan_logs', 'idx_qr_scan_datetime_personnel');
        $this->dropIndex('attendance_records', 'idx_attendance_date_late_personnel');
        $this->dropIndex('attendance_records', 'idx_attendance_date_status_personnel');
        $this->dropIndex('dtr_certifications', 'idx_dtr_month_status_personnel');
        $this->dropIndex('personnel', 'idx_personnel_scope_name');
    }

    private function addIndex(string $tableName, array $columns, string $indexName): void
    {
        if (! Schema::hasTable($tableName) || Schema::hasIndex($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName): void {
            $table->index($columns, $indexName);
        });
    }

    private function dropIndex(string $tableName, string $indexName): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasIndex($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
            $table->dropIndex($indexName);
        });
    }
};
