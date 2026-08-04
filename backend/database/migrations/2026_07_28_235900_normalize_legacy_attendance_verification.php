<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Normalize legacy verification metadata before production integrity
     * constraints are installed. Ambiguous verification always fails closed.
     */
    public function up(): void
    {
        if (
            ! Schema::hasTable('attendance_records')
            || ! Schema::hasTable('attendance_change_logs')
        ) {
            return;
        }

        DB::transaction(function (): void {
            $this->inconsistentRecords()
                ->select([
                    'attendance_id',
                    'is_verified',
                    'verified_by',
                    'verified_at',
                ])
                ->orderBy('attendance_id')
                ->chunkById(100, function ($records): void {
                    foreach ($records as $record) {
                        $oldValues = [
                            'is_verified' => (bool) $record->is_verified,
                            'verified_by' => $record->verified_by,
                            'verified_at' => $record->verified_at,
                        ];
                        $newValues = [
                            'is_verified' => false,
                            'verified_by' => null,
                            'verified_at' => null,
                        ];

                        DB::table('attendance_records')
                            ->where('attendance_id', $record->attendance_id)
                            ->update($newValues);

                        DB::table('attendance_change_logs')->insert([
                            'attendance_id' => $record->attendance_id,
                            'changed_by' => null,
                            'action_type' => 'Unverified',
                            'old_values' => json_encode($oldValues, JSON_THROW_ON_ERROR),
                            'new_values' => json_encode($newValues, JSON_THROW_ON_ERROR),
                            'reason' => 'Migration normalized inconsistent legacy verification metadata.',
                            'created_at' => now(),
                        ]);
                    }
                }, 'attendance_id');
        });
    }

    public function down(): void
    {
        // Verification cannot be safely restored from ambiguous legacy data.
        // The audit rows are deliberately retained.
    }

    private function inconsistentRecords(): Builder
    {
        return DB::table('attendance_records')
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
            });
    }
};
