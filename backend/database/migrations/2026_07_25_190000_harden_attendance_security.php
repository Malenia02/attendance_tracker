<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dtr_certifications', function (Blueprint $table): void {
            $table->json('certified_snapshot')->nullable()->after('remarks');
            $table->char('certified_hash', 64)->nullable()->after('certified_snapshot');
        });

        Schema::table('dtr_status_logs', function (Blueprint $table): void {
            $table->string('ip_address', 45)->nullable()->after('remarks');
            $table->string('user_agent', 500)->nullable()->after('ip_address');
            $table->uuid('request_id')->nullable()->after('user_agent')->index();
        });

        Schema::table('qr_scan_logs', function (Blueprint $table): void {
            $table->decimal('location_accuracy_meters', 8, 2)->nullable()->after('longitude');
            $table->dateTime('position_recorded_at')->nullable()->after('location_accuracy_meters');
        });

        Schema::table('attendance_change_logs', function (Blueprint $table): void {
            $table->dropForeign('fk_attendance_change_attendance');
            $table->foreign('attendance_id', 'fk_attendance_change_attendance')
                ->references('attendance_id')
                ->on('attendance_records')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
        });

        Schema::table('dtr_status_logs', function (Blueprint $table): void {
            $table->dropForeign('dtr_status_logs_dtr_certification_id_foreign');
            $table->foreign(
                'dtr_certification_id',
                'dtr_status_logs_dtr_certification_id_foreign'
            )
                ->references('dtr_certification_id')
                ->on('dtr_certifications')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('dtr_status_logs', function (Blueprint $table): void {
            $table->dropForeign('dtr_status_logs_dtr_certification_id_foreign');
            $table->foreign(
                'dtr_certification_id',
                'dtr_status_logs_dtr_certification_id_foreign'
            )
                ->references('dtr_certification_id')
                ->on('dtr_certifications')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
        });

        Schema::table('attendance_change_logs', function (Blueprint $table): void {
            $table->dropForeign('fk_attendance_change_attendance');
            $table->foreign('attendance_id', 'fk_attendance_change_attendance')
                ->references('attendance_id')
                ->on('attendance_records')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
        });

        Schema::table('qr_scan_logs', function (Blueprint $table): void {
            $table->dropColumn(['location_accuracy_meters', 'position_recorded_at']);
        });

        Schema::table('dtr_status_logs', function (Blueprint $table): void {
            $table->dropIndex(['request_id']);
            $table->dropColumn(['ip_address', 'user_agent', 'request_id']);
        });

        Schema::table('dtr_certifications', function (Blueprint $table): void {
            $table->dropColumn(['certified_snapshot', 'certified_hash']);
        });
    }
};
