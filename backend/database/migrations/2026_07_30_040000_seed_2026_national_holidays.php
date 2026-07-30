<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Insert the official nationwide 2026 calendar.
     *
     * The baseline comes from Proclamation No. 1006, s. 2025, with the
     * subsequently proclaimed Eid'l Fitr and Eid'l Adha dates. Local holidays,
     * office suspensions, and optional duty days remain administrator-managed.
     */
    public function up(): void
    {
        if (! Schema::hasTable('holidays')) {
            return;
        }

        $now = now();
        $standardSource = 'Official 2026 national calendar — Proclamation No. 1006, s. 2025.';
        $rows = [
            ['2026-01-01', "New Year's Day", 'Regular Holiday', $standardSource],
            ['2026-02-17', 'Chinese New Year', 'Special Non-Working Holiday', $standardSource],
            ['2026-02-25', 'EDSA People Power Revolution Anniversary', 'Special Working Holiday', $standardSource],
            ['2026-03-20', "Eid'l Fitr (Feast of Ramadhan)", 'Regular Holiday', 'Proclamation No. 1189, s. 2026.'],
            ['2026-04-02', 'Maundy Thursday', 'Regular Holiday', $standardSource],
            ['2026-04-03', 'Good Friday', 'Regular Holiday', $standardSource],
            ['2026-04-04', 'Black Saturday', 'Special Non-Working Holiday', $standardSource],
            ['2026-04-09', 'Araw ng Kagitingan', 'Regular Holiday', $standardSource],
            ['2026-05-01', 'Labor Day', 'Regular Holiday', $standardSource],
            ['2026-05-27', "Eid'l Adha (Feast of Sacrifice)", 'Regular Holiday', 'Proclamation No. 1264, s. 2026.'],
            ['2026-06-12', 'Independence Day', 'Regular Holiday', $standardSource],
            ['2026-08-21', 'Ninoy Aquino Day', 'Special Non-Working Holiday', $standardSource],
            ['2026-08-31', 'National Heroes Day', 'Regular Holiday', $standardSource],
            ['2026-11-01', "All Saints' Day", 'Special Non-Working Holiday', $standardSource],
            ['2026-11-02', "All Souls' Day", 'Special Non-Working Holiday', $standardSource],
            ['2026-11-30', 'Bonifacio Day', 'Regular Holiday', $standardSource],
            ['2026-12-08', 'Feast of the Immaculate Conception of Mary', 'Special Non-Working Holiday', $standardSource],
            ['2026-12-24', 'Christmas Eve', 'Special Non-Working Holiday', $standardSource],
            ['2026-12-25', 'Christmas Day', 'Regular Holiday', $standardSource],
            ['2026-12-30', 'Rizal Day', 'Regular Holiday', $standardSource],
            ['2026-12-31', 'Last Day of the Year', 'Special Non-Working Holiday', $standardSource],
        ];

        DB::table('holidays')->insertOrIgnore(
            array_map(
                fn (array $holiday): array => [
                    'holiday_date' => $holiday[0],
                    'holiday_name' => $holiday[1],
                    'holiday_type' => $holiday[2],
                    'scope' => 'National',
                    'department_id' => null,
                    'description' => $holiday[3],
                    'created_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $rows
            )
        );
    }

    /**
     * Remove only untouched system baseline records when explicitly rolled
     * back. Administrator-created or subsequently edited records are retained.
     */
    public function down(): void
    {
        if (! Schema::hasTable('holidays')) {
            return;
        }

        DB::table('holidays')
            ->whereNull('created_by')
            ->where('scope', 'National')
            ->whereIn('description', [
                'Official 2026 national calendar — Proclamation No. 1006, s. 2025.',
                'Proclamation No. 1189, s. 2026.',
                'Proclamation No. 1264, s. 2026.',
            ])
            ->delete();
    }
};
