<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE attendance_records MODIFY attendance_status
             ENUM('Present','Half Day','Absent','Leave','Holiday','Rest Day',
                  'Official Business','Work From Home','Incomplete')
             NOT NULL DEFAULT 'Incomplete'"
        );
    }

    public function down(): void
    {
        DB::table('attendance_records')
            ->where('attendance_status', 'Half Day')
            ->update(['attendance_status' => 'Incomplete']);

        DB::statement(
            "ALTER TABLE attendance_records MODIFY attendance_status
             ENUM('Present','Absent','Leave','Holiday','Rest Day',
                  'Official Business','Work From Home','Incomplete')
             NOT NULL DEFAULT 'Incomplete'"
        );
    }
};
