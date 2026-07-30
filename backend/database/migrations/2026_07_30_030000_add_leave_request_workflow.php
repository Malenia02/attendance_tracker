<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('leave_records')) {
            Schema::table('leave_records', function (Blueprint $table): void {
                if (! Schema::hasColumn('leave_records', 'request_number')) {
                    $table->string('request_number', 32)->nullable()->after('leave_id');
                }
                if (! Schema::hasColumn('leave_records', 'submitted_by')) {
                    $table->unsignedBigInteger('submitted_by')->nullable()->after('personnel_id');
                    $table->foreign('submitted_by', 'fk_leave_submitted_by')
                        ->references('user_id')
                        ->on('system_users')
                        ->nullOnDelete()
                        ->cascadeOnUpdate();
                }
                if (! Schema::hasColumn('leave_records', 'day_part')) {
                    $table->string('day_part', 20)->default('Full Day')->after('leave_type');
                }
                if (! Schema::hasColumn('leave_records', 'supporting_document')) {
                    $table->string('supporting_document', 500)->nullable()->after('reason');
                }
                if (! Schema::hasColumn('leave_records', 'review_remarks')) {
                    $table->string('review_remarks', 1000)->nullable()->after('approval_status');
                }
                if (! Schema::hasColumn('leave_records', 'cancelled_by')) {
                    $table->unsignedBigInteger('cancelled_by')->nullable()->after('approved_at');
                    $table->foreign('cancelled_by', 'fk_leave_cancelled_by')
                        ->references('user_id')
                        ->on('system_users')
                        ->nullOnDelete()
                        ->cascadeOnUpdate();
                }
                if (! Schema::hasColumn('leave_records', 'cancelled_at')) {
                    $table->dateTime('cancelled_at')->nullable()->after('cancelled_by');
                }
                if (! Schema::hasColumn('leave_records', 'cancellation_reason')) {
                    $table->string('cancellation_reason', 1000)->nullable()->after('cancelled_at');
                }
            });

            $this->addIndex('leave_records', ['request_number'], 'uq_leave_request_number', true);
            $this->addIndex(
                'leave_records',
                ['approval_status', 'date_from', 'date_to', 'personnel_id'],
                'idx_leave_status_dates_personnel'
            );
            $this->addIndex(
                'leave_records',
                ['submitted_by', 'created_at'],
                'idx_leave_submitter_created'
            );
        }

        if (! Schema::hasTable('leave_request_logs')) {
            Schema::create('leave_request_logs', function (Blueprint $table): void {
                $table->id('leave_request_log_id');
                $table->unsignedBigInteger('leave_id');
                $table->string('from_status', 20)->nullable();
                $table->string('to_status', 20);
                $table->string('remarks', 1000)->nullable();
                $table->unsignedBigInteger('changed_by')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('leave_id', 'fk_leave_log_request')
                    ->references('leave_id')
                    ->on('leave_records')
                    ->cascadeOnDelete()
                    ->cascadeOnUpdate();
                $table->foreign('changed_by', 'fk_leave_log_user')
                    ->references('user_id')
                    ->on('system_users')
                    ->nullOnDelete()
                    ->cascadeOnUpdate();
                $table->index(
                    ['leave_id', 'created_at'],
                    'idx_leave_log_request_created'
                );
            });
        }

        if (Schema::hasTable('attendance_records')
            && ! Schema::hasColumn('attendance_records', 'leave_record_id')) {
            Schema::table('attendance_records', function (Blueprint $table): void {
                $table->unsignedBigInteger('leave_record_id')
                    ->nullable()
                    ->after('schedule_id');
                $table->foreign('leave_record_id', 'fk_attendance_leave_request')
                    ->references('leave_id')
                    ->on('leave_records')
                    ->nullOnDelete()
                    ->cascadeOnUpdate();
                $table->index(
                    ['leave_record_id', 'attendance_date'],
                    'idx_attendance_leave_date'
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('attendance_records')
            && Schema::hasColumn('attendance_records', 'leave_record_id')) {
            Schema::table('attendance_records', function (Blueprint $table): void {
                $table->dropForeign('fk_attendance_leave_request');
                $table->dropIndex('idx_attendance_leave_date');
                $table->dropColumn('leave_record_id');
            });
        }

        Schema::dropIfExists('leave_request_logs');

        if (! Schema::hasTable('leave_records')) {
            return;
        }

        Schema::table('leave_records', function (Blueprint $table): void {
            if (Schema::hasColumn('leave_records', 'submitted_by')) {
                $table->dropForeign('fk_leave_submitted_by');
            }
            if (Schema::hasColumn('leave_records', 'cancelled_by')) {
                $table->dropForeign('fk_leave_cancelled_by');
            }

            $columns = [
                'request_number',
                'submitted_by',
                'day_part',
                'supporting_document',
                'review_remarks',
                'cancelled_by',
                'cancelled_at',
                'cancellation_reason',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('leave_records', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function addIndex(
        string $tableName,
        array $columns,
        string $indexName,
        bool $unique = false
    ): void {
        if (Schema::hasIndex($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use (
            $columns,
            $indexName,
            $unique
        ): void {
            $unique
                ? $table->unique($columns, $indexName)
                : $table->index($columns, $indexName);
        });
    }
};
