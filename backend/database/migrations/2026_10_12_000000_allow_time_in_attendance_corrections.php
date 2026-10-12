<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_correction_requests', function (Blueprint $table): void {
            $table->dropForeign('attendance_correction_requests_attendance_id_foreign');
            $table->unsignedBigInteger('attendance_id')->nullable()->change();
            $table->enum('missing_field', [
                'morning_time_in',
                'morning_time_out',
                'afternoon_time_in',
                'afternoon_time_out',
            ])->change();
            $table->unsignedBigInteger('schedule_id')->nullable()->after('attendance_id');
            $table->foreign('attendance_id')
                ->references('attendance_id')
                ->on('attendance_records')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreign('schedule_id')
                ->references('schedule_id')
                ->on('work_schedules')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        $hasNewRequestData = DB::table('attendance_correction_requests')
            ->whereNull('attendance_id')
            ->orWhereIn('missing_field', ['morning_time_in', 'afternoon_time_in'])
            ->exists();

        if ($hasNewRequestData) {
            throw new RuntimeException(
                'Cannot roll back time-in correction support while requests depend on it.'
            );
        }

        Schema::table('attendance_correction_requests', function (Blueprint $table): void {
            $table->dropForeign('attendance_correction_requests_attendance_id_foreign');
            $table->dropForeign('attendance_correction_requests_schedule_id_foreign');
            $table->dropColumn('schedule_id');
            $table->enum('missing_field', ['morning_time_out', 'afternoon_time_out'])->change();
            $table->unsignedBigInteger('attendance_id')->nullable(false)->change();
            $table->foreign('attendance_id')
                ->references('attendance_id')
                ->on('attendance_records')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
        });
    }
};
