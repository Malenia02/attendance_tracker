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
        $this->assertNoDuplicateHolidayScopes();
        $this->assertCheckConstraintDataIsValid();
        $this->hashExistingQrCredentials();

        $this->ensureActivityRequestId();
        $this->ensureUniqueIndex(
            'personnel',
            ['email'],
            'uq_personnel_email'
        );
        $this->ensureUniqueIndex(
            'time_logs',
            ['attendance_id', 'log_type'],
            'uq_time_logs_attendance_type'
        );

        DB::table('qr_scan_logs')
            ->whereNotNull('scanned_by')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('system_users')
                    ->whereColumn('system_users.user_id', 'qr_scan_logs.scanned_by');
            })
            ->update(['scanned_by' => null]);

        $this->ensureQrScanOperatorForeignKey();
        $this->ensureHolidayScopeIntegrity();

        if (DB::getDriverName() === 'mysql') {
            $this->preserveOfficialHistory();
            $this->ensureAttendanceVerifierForeignKey(
                'restrict',
                'restrict'
            );

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

                if ($this->checkConstraintExists($tableName, $constraint)) {
                    DB::statement(
                        "ALTER TABLE `{$tableName}` DROP CHECK `{$constraint}`"
                    );
                }
            }

            DB::statement(
                'ALTER TABLE work_schedules '
                .'MODIFY `friday` TINYINT(1) NOT NULL DEFAULT 1'
            );

            $this->ensureAttendanceVerifierForeignKey(
                'set null',
                'cascade'
            );
            $this->restoreHistoricalCascades();
        }

        if (Schema::hasIndex('holidays', 'uq_holiday_date_scope_type')) {
            Schema::table('holidays', function (Blueprint $table): void {
                $table->dropUnique('uq_holiday_date_scope_type');
            });
        }

        if (Schema::hasColumn('holidays', 'scope_department_key')) {
            if ($this->foreignKeyNamed('holidays', 'fk_holiday_department')) {
                Schema::table('holidays', function (Blueprint $table): void {
                    $table->dropForeign('fk_holiday_department');
                });
            }

            Schema::table('holidays', function (Blueprint $table): void {
                $table->dropColumn('scope_department_key');
            });
        }

        $this->ensureHolidayDepartmentForeignKey('set null', 'cascade');

        if ($this->foreignKeyNamed('qr_scan_logs', 'fk_qr_scan_scanned_by')) {
            Schema::table('qr_scan_logs', function (Blueprint $table): void {
                $table->dropForeign('fk_qr_scan_scanned_by');
            });
        }

        if (Schema::hasIndex('time_logs', 'uq_time_logs_attendance_type')) {
            Schema::table('time_logs', function (Blueprint $table): void {
                $table->dropUnique('uq_time_logs_attendance_type');
            });
        }

        if (Schema::hasIndex('personnel', 'uq_personnel_email')) {
            Schema::table('personnel', function (Blueprint $table): void {
                $table->dropUnique('uq_personnel_email');
            });
        }

        // Keep the audit correlation ID on rollback. Older databases and
        // forward-only repair migrations may own this column, and removing it
        // would make authentication/activity logging fail at runtime.
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

    private function assertNoDuplicateHolidayScopes(): void
    {
        $duplicatesExist = DB::table('holidays')
            ->select([
                'holiday_date',
                'holiday_type',
                DB::raw('COALESCE(department_id, 0) AS department_scope'),
            ])
            ->groupBy([
                'holiday_date',
                'holiday_type',
                DB::raw('COALESCE(department_id, 0)'),
            ])
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicatesExist) {
            throw new RuntimeException(
                'Duplicate holidays for the same date, type, and office scope must be corrected before this migration can run.'
            );
        }
    }

    private function assertCheckConstraintDataIsValid(): void
    {
        $invalidDataChecks = [
            [
                'table' => 'departments',
                'invalid' => fn () => DB::table('departments')
                    ->where(function ($query): void {
                        $query
                            ->where(function ($coordinates): void {
                                $coordinates->whereNull('latitude')
                                    ->whereNotNull('longitude');
                            })
                            ->orWhere(function ($coordinates): void {
                                $coordinates->whereNotNull('latitude')
                                    ->whereNull('longitude');
                            })
                            ->orWhereNotBetween('latitude', [-90, 90])
                            ->orWhereNotBetween('longitude', [-180, 180]);
                    })
                    ->exists(),
                'message' => 'Department coordinates contain an invalid latitude or longitude pair.',
            ],
            [
                'table' => 'departments',
                'invalid' => fn () => DB::table('departments')
                    ->whereNotBetween('allowed_radius_meters', [25, 5000])
                    ->exists(),
                'message' => 'Department attendance radiuses must be between 25 and 5,000 meters.',
            ],
            [
                'table' => 'personnel',
                'invalid' => fn () => DB::table('personnel')
                    ->whereNotNull('employment_start_date')
                    ->whereNotNull('employment_end_date')
                    ->whereColumn('employment_end_date', '<', 'employment_start_date')
                    ->exists(),
                'message' => 'Personnel employment end dates cannot precede their start dates.',
            ],
            [
                'table' => 'personnel_schedules',
                'invalid' => fn () => DB::table('personnel_schedules')
                    ->whereNotNull('effective_to')
                    ->whereColumn('effective_to', '<', 'effective_from')
                    ->exists(),
                'message' => 'Personnel schedule end dates cannot precede their start dates.',
            ],
            [
                'table' => 'attendance_records',
                'invalid' => fn () => DB::table('attendance_records')
                    ->where(function ($query): void {
                        $query
                            ->where(function ($unverified): void {
                                $unverified->where('is_verified', false)
                                    ->where(function ($metadata): void {
                                        $metadata->whereNotNull('verified_by')
                                            ->orWhereNotNull('verified_at');
                                    });
                            })
                            ->orWhere(function ($verified): void {
                                $verified->where('is_verified', true)
                                    ->where(function ($metadata): void {
                                        $metadata->whereNull('verified_by')
                                            ->orWhereNull('verified_at');
                                    });
                            });
                    })
                    ->exists(),
                'message' => 'Attendance verification flags and reviewer metadata are inconsistent.',
            ],
            [
                'table' => 'holidays',
                'invalid' => fn () => DB::table('holidays')
                    ->where(function ($query): void {
                        $query
                            ->where(function ($national): void {
                                $national->where('scope', 'National')
                                    ->whereNotNull('department_id');
                            })
                            ->orWhere(function ($local): void {
                                $local->where('scope', '<>', 'National')
                                    ->whereNull('department_id');
                            });
                    })
                    ->exists(),
                'message' => 'Holiday scope and office assignment data are inconsistent.',
            ],
            [
                'table' => 'work_schedules',
                'invalid' => fn () => DB::table('work_schedules')
                    ->whereColumn('morning_start', '>=', 'morning_end')
                    ->orWhereColumn('afternoon_start', '>=', 'afternoon_end')
                    ->exists(),
                'message' => 'Work schedule start times must precede their end times.',
            ],
        ];

        foreach ($invalidDataChecks as $check) {
            if (! Schema::hasTable($check['table'])) {
                continue;
            }

            if (($check['invalid'])()) {
                throw new RuntimeException($check['message']);
            }
        }
    }

    private function hashExistingQrCredentials(): void
    {
        DB::table('personnel')
            ->whereNotNull('qr_login_code')
            ->where('qr_login_code', '<>', '')
            ->orderBy('personnel_id')
            ->chunkById(100, function ($personnel): void {
                foreach ($personnel as $person) {
                    $credential = (string) $person->qr_login_code;

                    if (preg_match('/\A[a-f0-9]{64}\z/i', $credential) === 1) {
                        continue;
                    }

                    DB::table('personnel')
                        ->where('personnel_id', $person->personnel_id)
                        ->update([
                            'qr_login_code' => hash('sha256', $credential),
                        ]);
                }
            }, 'personnel_id');
    }

    private function ensureActivityRequestId(): void
    {
        if (! Schema::hasColumn('activity_logs', 'request_id')) {
            Schema::table('activity_logs', function (Blueprint $table): void {
                $table->uuid('request_id')->nullable()->after('user_agent');
            });
        }

        if (! Schema::hasIndex('activity_logs', ['request_id'])) {
            Schema::table('activity_logs', function (Blueprint $table): void {
                $table->index('request_id', 'idx_activity_request_id');
            });
        }
    }

    private function ensureUniqueIndex(
        string $tableName,
        array $columns,
        string $indexName
    ): void {
        if (
            Schema::hasIndex($tableName, $indexName)
            || Schema::hasIndex($tableName, $columns, 'unique')
        ) {
            return;
        }

        Schema::table(
            $tableName,
            function (Blueprint $table) use ($columns, $indexName): void {
                $table->unique($columns, $indexName);
            }
        );
    }

    private function ensureQrScanOperatorForeignKey(): void
    {
        $foreign = $this->foreignKeyForColumns(
            'qr_scan_logs',
            ['scanned_by']
        );

        if ($foreign) {
            if (
                $foreign['foreign_table'] !== 'system_users'
                || $foreign['foreign_columns'] !== ['user_id']
            ) {
                throw new RuntimeException(
                    'The existing QR scan operator foreign key targets an unexpected table.'
                );
            }

            return;
        }

        Schema::table('qr_scan_logs', function (Blueprint $table): void {
            $table->foreign('scanned_by', 'fk_qr_scan_scanned_by')
                ->references('user_id')
                ->on('system_users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
        });
    }

    private function ensureHolidayScopeIntegrity(): void
    {
        if (! Schema::hasColumn('holidays', 'scope_department_key')) {
            if ($this->foreignKeyNamed('holidays', 'fk_holiday_department')) {
                Schema::table('holidays', function (Blueprint $table): void {
                    $table->dropForeign('fk_holiday_department');
                });
            }

            Schema::table('holidays', function (Blueprint $table): void {
                $table->unsignedInteger('scope_department_key')
                    ->storedAs('COALESCE(`department_id`, 0)')
                    ->after('department_id');
            });
        }

        $this->ensureHolidayDepartmentForeignKey('restrict', 'restrict');

        $this->ensureUniqueIndex(
            'holidays',
            ['holiday_date', 'scope_department_key', 'holiday_type'],
            'uq_holiday_date_scope_type'
        );
    }

    private function ensureHolidayDepartmentForeignKey(
        string $deleteRule,
        string $updateRule
    ): void {
        $foreign = $this->foreignKeyNamed(
            'holidays',
            'fk_holiday_department'
        );

        if (
            $foreign
            && $foreign['on_delete'] === $deleteRule
            && $foreign['on_update'] === $updateRule
        ) {
            return;
        }

        Schema::table(
            'holidays',
            function (Blueprint $table) use (
                $deleteRule,
                $foreign,
                $updateRule
            ): void {
                if ($foreign) {
                    $table->dropForeign('fk_holiday_department');
                }

                $definition = $table
                    ->foreign('department_id', 'fk_holiday_department')
                    ->references('department_id')
                    ->on('departments');

                if ($deleteRule === 'set null') {
                    $definition->nullOnDelete();
                } else {
                    $definition->restrictOnDelete();
                }

                if ($updateRule === 'cascade') {
                    $definition->cascadeOnUpdate();
                } else {
                    $definition->restrictOnUpdate();
                }
            }
        );
    }

    private function ensureAttendanceVerifierForeignKey(
        string $deleteRule,
        string $updateRule
    ): void {
        $foreign = $this->foreignKeyNamed(
            'attendance_records',
            'fk_attendance_verified_by'
        );

        if (
            $foreign
            && $foreign['on_delete'] === $deleteRule
            && $foreign['on_update'] === $updateRule
        ) {
            return;
        }

        Schema::table(
            'attendance_records',
            function (Blueprint $table) use (
                $deleteRule,
                $foreign,
                $updateRule
            ): void {
                if ($foreign) {
                    $table->dropForeign('fk_attendance_verified_by');
                }

                $definition = $table
                    ->foreign('verified_by', 'fk_attendance_verified_by')
                    ->references('user_id')
                    ->on('system_users');

                if ($deleteRule === 'set null') {
                    $definition->nullOnDelete();
                } else {
                    $definition->restrictOnDelete();
                }

                if ($updateRule === 'cascade') {
                    $definition->cascadeOnUpdate();
                } else {
                    $definition->restrictOnUpdate();
                }
            }
        );
    }

    private function preserveOfficialHistory(): void
    {
        foreach ([
            ['attendance_records', 'fk_attendance_personnel'],
            ['time_logs', 'fk_timelog_personnel'],
            ['dtr_certifications', 'fk_dtr_personnel'],
            ['leave_records', 'fk_leave_personnel'],
        ] as [$tableName, $foreignName]) {
            $this->ensurePersonnelForeignDeleteRule(
                $tableName,
                $foreignName,
                'restrict'
            );
        }
    }

    private function restoreHistoricalCascades(): void
    {
        foreach ([
            ['attendance_records', 'fk_attendance_personnel'],
            ['time_logs', 'fk_timelog_personnel'],
            ['dtr_certifications', 'fk_dtr_personnel'],
            ['leave_records', 'fk_leave_personnel'],
        ] as [$tableName, $foreignName]) {
            $this->ensurePersonnelForeignDeleteRule(
                $tableName,
                $foreignName,
                'cascade'
            );
        }
    }

    private function ensurePersonnelForeignDeleteRule(
        string $tableName,
        string $foreignName,
        string $deleteRule
    ): void {
        $foreign = $this->foreignKeyNamed($tableName, $foreignName);

        if (
            $foreign
            && $foreign['on_delete'] === $deleteRule
            && $foreign['on_update'] === 'cascade'
        ) {
            return;
        }

        Schema::table(
            $tableName,
            function (Blueprint $table) use (
                $foreign,
                $foreignName,
                $deleteRule
            ): void {
                if ($foreign) {
                    $table->dropForeign($foreignName);
                }

                $definition = $table
                    ->foreign('personnel_id', $foreignName)
                    ->references('personnel_id')
                    ->on('personnel')
                    ->cascadeOnUpdate();

                if ($deleteRule === 'restrict') {
                    $definition->restrictOnDelete();
                } else {
                    $definition->cascadeOnDelete();
                }
            }
        );
    }

    private function addMySqlChecks(): void
    {
        $this->addCheckIfMissing(
            'departments',
            'chk_department_coordinates',
            '(`latitude` IS NULL AND `longitude` IS NULL) OR '
            .'(`latitude` BETWEEN -90 AND 90 AND `longitude` BETWEEN -180 AND 180)'
        );
        $this->addCheckIfMissing(
            'departments',
            'chk_department_radius',
            '`allowed_radius_meters` BETWEEN 25 AND 5000'
        );
        $this->addCheckIfMissing(
            'personnel',
            'chk_personnel_employment_dates',
            '`employment_end_date` IS NULL OR `employment_start_date` IS NULL '
            .'OR `employment_end_date` >= `employment_start_date`'
        );
        $this->addCheckIfMissing(
            'personnel_schedules',
            'chk_personnel_schedule_dates',
            '`effective_to` IS NULL OR `effective_to` >= `effective_from`'
        );
        $this->addCheckIfMissing(
            'attendance_records',
            'chk_attendance_verification',
            '(`is_verified` = 0 AND `verified_by` IS NULL AND `verified_at` IS NULL) OR '
            .'(`is_verified` = 1 AND `verified_by` IS NOT NULL AND `verified_at` IS NOT NULL)'
        );
        $this->addCheckIfMissing(
            'holidays',
            'chk_holiday_scope_department',
            '(`scope` = \'National\' AND `department_id` IS NULL) OR '
            .'(`scope` <> \'National\' AND `department_id` IS NOT NULL)'
        );
        $this->addCheckIfMissing(
            'work_schedules',
            'chk_schedule_official_hours',
            '`morning_start` < `morning_end` AND '
            .'`afternoon_start` < `afternoon_end`'
        );
    }

    private function addCheckIfMissing(
        string $tableName,
        string $constraintName,
        string $expression
    ): void {
        if ($this->checkConstraintExists($tableName, $constraintName)) {
            return;
        }

        DB::statement(
            "ALTER TABLE `{$tableName}` "
            ."ADD CONSTRAINT `{$constraintName}` CHECK ({$expression})"
        );
    }

    private function checkConstraintExists(
        string $tableName,
        string $constraintName
    ): bool {
        if (DB::getDriverName() !== 'mysql') {
            return false;
        }

        return DB::selectOne(
            'SELECT 1 AS present '
            .'FROM information_schema.TABLE_CONSTRAINTS '
            .'WHERE CONSTRAINT_SCHEMA = DATABASE() '
            .'AND TABLE_NAME = ? '
            .'AND CONSTRAINT_NAME = ? '
            ."AND CONSTRAINT_TYPE = 'CHECK' "
            .'LIMIT 1',
            [$tableName, $constraintName]
        ) !== null;
    }

    private function foreignKeyNamed(
        string $tableName,
        string $foreignName
    ): ?array {
        foreach (Schema::getForeignKeys($tableName) as $foreign) {
            if (($foreign['name'] ?? null) === $foreignName) {
                return $foreign;
            }
        }

        return null;
    }

    private function foreignKeyForColumns(
        string $tableName,
        array $columns
    ): ?array {
        foreach (Schema::getForeignKeys($tableName) as $foreign) {
            if ($foreign['columns'] === $columns) {
                return $foreign;
            }
        }

        return null;
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
