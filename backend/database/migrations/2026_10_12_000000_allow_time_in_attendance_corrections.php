<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $attendanceForeignKey = $this->foreignKeyForColumn('attendance_id');
        if (! $attendanceForeignKey) {
            $this->assertNoOrphanedReferences(
                'attendance_id',
                'attendance_records',
                'attendance_id'
            );
        }

        if (Schema::hasColumn('attendance_correction_requests', 'schedule_id')
            && ! $this->foreignKeyForColumn('schedule_id')) {
            $this->assertNoOrphanedReferences('schedule_id', 'work_schedules', 'schedule_id');
        }

        $this->dropForeignKeysForColumn('attendance_id');
        $this->dropForeignKeysForColumn('schedule_id');

        Schema::table('attendance_correction_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('attendance_id')->nullable()->change();
            $table->enum('missing_field', [
                'morning_time_in',
                'morning_time_out',
                'afternoon_time_in',
                'afternoon_time_out',
            ])->change();
            if (! Schema::hasColumn('attendance_correction_requests', 'schedule_id')) {
                $table->unsignedInteger('schedule_id')->nullable()->after('attendance_id');
            } else {
                $table->unsignedInteger('schedule_id')->nullable()->change();
            }
        });

        if (! $this->foreignKeyForColumn('attendance_id')) {
            Schema::table('attendance_correction_requests', function (Blueprint $table): void {
                $table->foreign('attendance_id')
                    ->references('attendance_id')
                    ->on('attendance_records')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();
            });
        }

        if (! $this->foreignKeyForColumn('schedule_id')) {
            Schema::table('attendance_correction_requests', function (Blueprint $table): void {
                $table->foreign('schedule_id')
                    ->references('schedule_id')
                    ->on('work_schedules')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();
            });
        }
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

        $this->dropForeignKeysForColumn('attendance_id');
        $this->dropForeignKeysForColumn('schedule_id');

        Schema::table('attendance_correction_requests', function (Blueprint $table): void {
            if (Schema::hasColumn('attendance_correction_requests', 'schedule_id')) {
                $table->dropColumn('schedule_id');
            }

            $table->enum('missing_field', ['morning_time_out', 'afternoon_time_out'])->change();
            $table->unsignedBigInteger('attendance_id')->nullable(false)->change();
        });

        if (! $this->foreignKeyForColumn('attendance_id')) {
            $this->assertNoOrphanedReferences(
                'attendance_id',
                'attendance_records',
                'attendance_id'
            );
            Schema::table('attendance_correction_requests', function (Blueprint $table): void {
                $table->foreign('attendance_id')
                    ->references('attendance_id')
                    ->on('attendance_records')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();
            });
        }
    }

    private function foreignKeyForColumn(string $column): ?array
    {
        foreach (Schema::getForeignKeys('attendance_correction_requests') as $foreignKey) {
            if (($foreignKey['columns'] ?? []) === [$column]) {
                return $foreignKey;
            }
        }

        return null;
    }

    private function dropForeignKeysForColumn(string $column): void
    {
        foreach (Schema::getForeignKeys('attendance_correction_requests') as $foreignKey) {
            if (($foreignKey['columns'] ?? []) !== [$column]) {
                continue;
            }

            Schema::table('attendance_correction_requests', function (Blueprint $table) use ($foreignKey): void {
                $foreignKeyIdentifier = DB::getDriverName() === 'sqlite'
                    ? $foreignKey['columns']
                    : $foreignKey['name'];
                $table->dropForeign($foreignKeyIdentifier);
            });
        }
    }

    private function assertNoOrphanedReferences(
        string $column,
        string $referencedTable,
        string $referencedColumn
    ): void {
        $hasOrphans = DB::table('attendance_correction_requests')
            ->whereNotNull($column)
            ->whereNotExists(function ($query) use ($column, $referencedTable, $referencedColumn): void {
                $query->selectRaw('1')
                    ->from($referencedTable)
                    ->whereColumn($referencedTable.'.'.$referencedColumn, 'attendance_correction_requests.'.$column);
            })
            ->exists();

        if ($hasOrphans) {
            throw new RuntimeException(
                "Cannot add the {$column} foreign key because existing correction requests reference missing records."
            );
        }
    }
};
