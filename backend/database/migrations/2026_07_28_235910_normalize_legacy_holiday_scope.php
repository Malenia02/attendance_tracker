<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Repair legacy scope combinations without broadening an office event into
     * a national event. Scoped records inherit the creator's active office.
     */
    public function up(): void
    {
        if (! Schema::hasTable('holidays')) {
            return;
        }

        DB::transaction(function (): void {
            $nationalRecords = DB::table('holidays')
                ->where('scope', 'National')
                ->whereNotNull('department_id')
                ->lockForUpdate()
                ->get(['holiday_id', 'department_id']);

            foreach ($nationalRecords as $holiday) {
                DB::table('holidays')
                    ->where('holiday_id', $holiday->holiday_id)
                    ->update(['department_id' => null]);

                $this->audit(
                    $holiday->holiday_id,
                    'Removed an office assignment from a legacy national holiday.'
                );
            }

            $scopedRecords = DB::table('holidays as holidays')
                ->leftJoin('system_users as users', 'users.user_id', '=', 'holidays.created_by')
                ->leftJoin('personnel as personnel', 'personnel.personnel_id', '=', 'users.personnel_id')
                ->leftJoin('departments as departments', function ($join): void {
                    $join->on('departments.department_id', '=', 'personnel.department_id')
                        ->where('departments.status', '=', 'Active');
                })
                ->where('holidays.scope', '<>', 'National')
                ->whereNull('holidays.department_id')
                ->lockForUpdate()
                ->get([
                    'holidays.holiday_id',
                    'holidays.holiday_name',
                    'departments.department_id as creator_department_id',
                ]);

            $unresolved = $scopedRecords
                ->whereNull('creator_department_id')
                ->pluck('holiday_name');

            if ($unresolved->isNotEmpty()) {
                throw new RuntimeException(
                    'These scoped holidays need an office assignment before migration: '
                    .$unresolved->implode(', ').'.'
                );
            }

            foreach ($scopedRecords as $holiday) {
                DB::table('holidays')
                    ->where('holiday_id', $holiday->holiday_id)
                    ->update(['department_id' => $holiday->creator_department_id]);

                $this->audit(
                    $holiday->holiday_id,
                    'Assigned a legacy scoped holiday to its creator\'s active office.'
                );
            }
        });
    }

    public function down(): void
    {
        // Scope repairs are intentionally retained because reversing them would
        // recreate invalid and potentially global calendar behavior.
    }

    private function audit(int $holidayId, string $description): void
    {
        if (! Schema::hasTable('activity_logs')) {
            return;
        }

        DB::table('activity_logs')->insert([
            'user_id' => null,
            'activity_type' => 'MIGRATION_DATA_REPAIR',
            'description' => $description,
            'entity_type' => 'holidays',
            'entity_id' => $holidayId,
            'ip_address' => null,
            'user_agent' => null,
            'created_at' => now(),
        ]);
    }
};
