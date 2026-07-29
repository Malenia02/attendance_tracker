<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertNoDuplicatePersonnelEmails();
        $this->assertNoDuplicateAttendanceActions();
        $this->hashExistingQrCredentials();

        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->uuid('request_id')->nullable()->after('user_agent')->index();
        });

        Schema::table('personnel', function (Blueprint $table): void {
            $table->unique('email', 'uq_personnel_email');
        });

        Schema::table('time_logs', function (Blueprint $table): void {
            $table->unique(
                ['attendance_id', 'log_type'],
                'uq_time_logs_attendance_type'
            );
        });

        DB::table('qr_scan_logs')
            ->whereNotNull('scanned_by')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('system_users')
                    ->whereColumn('system_users.user_id', 'qr_scan_logs.scanned_by');
            })
            ->update(['scanned_by' => null]);

        Schema::table('qr_scan_logs', function (Blueprint $table): void {
            $table->foreign('scanned_by', 'fk_qr_scan_scanned_by')
                ->references('user_id')
                ->on('system_users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
        });

        Schema::table('holidays', function (Blueprint $table): void {
            $table->unsignedBigInteger('scope_department_key')
                ->storedAs('COALESCE(`department_id`, 0)')
                ->after('department_id');
            $table->unique(
                ['holiday_date', 'scope_department_key', 'holiday_type'],
                'uq_holiday_date_scope_type'
            );
        });

        $this->preserveOfficialHistory();

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE work_schedules '
                .'MODIFY `friday` TINYINT(1) NOT NULL DEFAULT 0'
            );
            $this->addMySqlChecks();
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            foreach ([
                'chk_department_coordinates',
                'chk_department_radius',
                'chk_personnel_employment_dates',
                'chk_personnel_schedule_dates',
                'chk_attendance_verification',
                'chk_holiday_scope_department',
                'chk_schedule_official_hours',
            ] as $constraint) {
                $tableName = $this->constraintTable($constraint);
                DB::statement("ALTER TABLE `{$tableName}` DROP CHECK `{$constraint}`");
            }

            DB::statement(
                'ALTER TABLE work_schedules '
                .'MODIFY `friday` TINYINT(1) NOT NULL DEFAULT 1'
            );
        }

        $this->restoreHistoricalCascades();

        Schema::table('holidays', function (Blueprint $table): void {
            $table->dropUnique('uq_holiday_date_scope_type');
            $table->dropColumn('scope_department_key');
        });

        Schema::table('qr_scan_logs', function (Blueprint $table): void {
            $table->dropForeign('fk_qr_scan_scanned_by');
        });

        Schema::table('time_logs', function (Blueprint $table): void {
            $table->dropUnique('uq_time_logs_attendance_type');
        });

        Schema::table('personnel', function (Blueprint $table): void {
            $table->dropUnique('uq_personnel_email');
        });

        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->dropColumn('request_id');
        });
    }

    private function assertNoDuplicatePersonnelEmails(): void
    {
        $duplicatesExist = DB::table('personnel')
            ->select('email')
            ->whereNotNull('email')
            ->groupBy('email')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicatesExist) {
            throw new RuntimeException(
                'Duplicate personnel email addresses must be corrected before this migration can run.'
            );
        }
    }

    private function assertNoDuplicateAttendanceActions(): void
    {
        $duplicatesExist = DB::table('time_logs')
            ->select(['attendance_id', 'log_type'])
            ->whereNotNull('attendance_id')
            ->groupBy(['attendance_id', 'log_type'])
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicatesExist) {
            throw new RuntimeException(
                'Duplicate attendance time-log actions must be reviewed before this migration can run.'
            );
        }
    }

    private function hashExistingQrCredentials(): void
    {
        DB::table('personnel')
            ->whereNotNull('qr_login_code')
            ->orderBy('personnel_id')
            ->chunkById(100, function ($personnel): void {
                foreach ($personnel as $person) {
                    DB::table('personnel')
                        ->where('personnel_id', $person->personnel_id)
                        ->update([
                            'qr_login_code' => hash(
                                'sha256',
                                (string) $person->qr_login_code
                            ),
                        ]);
                }
            }, 'personnel_id');
    }

    private function preserveOfficialHistory(): void
    {
        Schema::table('attendance_records', function (Blueprint $table): void {
            $table->dropForeign('fk_attendance_personnel');
            $table->foreign('personnel_id', 'fk_attendance_personnel')
                ->references('personnel_id')
                ->on('personnel')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
        });

        Schema::table('time_logs', function (Blueprint $table): void {
            $table->dropForeign('fk_timelog_personnel');
            $table->foreign('personnel_id', 'fk_timelog_personnel')
                ->references('personnel_id')
                ->on('personnel')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
        });

        Schema::table('dtr_certifications', function (Blueprint $table): void {
            $table->dropForeign('fk_dtr_personnel');
            $table->foreign('personnel_id', 'fk_dtr_personnel')
                ->references('personnel_id')
                ->on('personnel')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
        });

        Schema::table('leave_records', function (Blueprint $table): void {
            $table->dropForeign('fk_leave_personnel');
            $table->foreign('personnel_id', 'fk_leave_personnel')
                ->references('personnel_id')
                ->on('personnel')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
        });
    }

    private function restoreHistoricalCascades(): void
    {
        foreach ([
            ['attendance_records', 'fk_attendance_personnel'],
            ['time_logs', 'fk_timelog_personnel'],
            ['dtr_certifications', 'fk_dtr_personnel'],
            ['leave_records', 'fk_leave_personnel'],
        ] as [$tableName, $foreignName]) {
            Schema::table($tableName, function (Blueprint $table) use ($foreignName): void {
                $table->dropForeign($foreignName);
                $table->foreign('personnel_id', $foreignName)
                    ->references('personnel_id')
                    ->on('personnel')
                    ->cascadeOnDelete()
                    ->cascadeOnUpdate();
            });
        }
    }

    private function addMySqlChecks(): void
    {
        DB::statement(
            'ALTER TABLE departments ADD CONSTRAINT chk_department_coordinates CHECK ('
            .'(`latitude` IS NULL AND `longitude` IS NULL) OR '
            .'(`latitude` BETWEEN -90 AND 90 AND `longitude` BETWEEN -180 AND 180))'
        );
        DB::statement(
            'ALTER TABLE departments ADD CONSTRAINT chk_department_radius '
            .'CHECK (`attendance_radius_meters` BETWEEN 25 AND 5000)'
        );
        DB::statement(
            'ALTER TABLE personnel ADD CONSTRAINT chk_personnel_employment_dates '
            .'CHECK (`employment_end_date` IS NULL OR `employment_start_date` IS NULL '
            .'OR `employment_end_date` >= `employment_start_date`)'
        );
        DB::statement(
            'ALTER TABLE personnel_schedules ADD CONSTRAINT chk_personnel_schedule_dates '
            .'CHECK (`effective_to` IS NULL OR `effective_to` >= `effective_from`)'
        );
        DB::statement(
            'ALTER TABLE attendance_records ADD CONSTRAINT chk_attendance_verification CHECK ('
            .'(`is_verified` = 0 AND `verified_by` IS NULL AND `verified_at` IS NULL) OR '
            .'(`is_verified` = 1 AND `verified_by` IS NOT NULL AND `verified_at` IS NOT NULL))'
        );
        DB::statement(
            'ALTER TABLE holidays ADD CONSTRAINT chk_holiday_scope_department CHECK ('
            .'(`scope` = \'National\' AND `department_id` IS NULL) OR '
            .'(`scope` <> \'National\' AND `department_id` IS NOT NULL))'
        );
        DB::statement(
            'ALTER TABLE work_schedules ADD CONSTRAINT chk_schedule_official_hours CHECK ('
            .'`morning_start` < `morning_end` AND '
            .'`afternoon_start` < `afternoon_end`)'
        );
    }

    private function constraintTable(string $constraint): string
    {
        return match ($constraint) {
            'chk_department_coordinates', 'chk_department_radius' => 'departments',
            'chk_personnel_employment_dates' => 'personnel',
            'chk_personnel_schedule_dates' => 'personnel_schedules',
            'chk_attendance_verification' => 'attendance_records',
            'chk_holiday_scope_department' => 'holidays',
            'chk_schedule_official_hours' => 'work_schedules',
        };
    }
};
