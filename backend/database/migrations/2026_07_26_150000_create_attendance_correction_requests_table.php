<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_correction_requests', function (Blueprint $table): void {
            $table->bigIncrements('attendance_correction_request_id');
            $table->unsignedBigInteger('attendance_id');
            $table->unsignedBigInteger('personnel_id');
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->date('attendance_date');
            $table->enum('missing_field', ['morning_time_out', 'afternoon_time_out']);
            $table->time('proposed_time');
            $table->string('reason', 500);
            $table->enum('request_status', ['Pending', 'Approved', 'Rejected', 'Cancelled'])
                ->default('Pending');
            $table->string('pending_key', 191)->nullable()->unique();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('review_remarks', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->foreign('attendance_id')
                ->references('attendance_id')
                ->on('attendance_records')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreign('personnel_id')
                ->references('personnel_id')
                ->on('personnel')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreign('submitted_by')
                ->references('user_id')
                ->on('system_users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->foreign('reviewed_by')
                ->references('user_id')
                ->on('system_users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->index(
                ['attendance_date', 'request_status'],
                'idx_attendance_correction_date_status'
            );
            $table->index(
                ['personnel_id', 'attendance_date'],
                'idx_attendance_correction_personnel_date'
            );
            $table->index(
                ['submitted_by', 'created_at'],
                'idx_attendance_correction_submitter'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_correction_requests');
    }
};
