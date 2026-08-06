<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('dtr_certifications', 'dtr_period')) {
            Schema::table('dtr_certifications', function (Blueprint $table): void {
                $table->enum('dtr_period', ['first_half', 'second_half', 'full_month'])
                    ->default('full_month')
                    ->after('dtr_month');
            });
        }

        // MySQL may be using the old composite unique index to support the
        // personnel foreign key. Give that constraint its own stable index
        // before replacing the unique key.
        if (! $this->indexExists('dtr_certifications', 'idx_dtr_personnel_fk')) {
            Schema::table('dtr_certifications', fn (Blueprint $table) => $table
                ->index('personnel_id', 'idx_dtr_personnel_fk'));
        }

        if ($this->indexExists('dtr_certifications', 'uq_dtr_personnel_month')) {
            Schema::table('dtr_certifications', fn (Blueprint $table) => $table
                ->dropUnique('uq_dtr_personnel_month'));
        }

        if (! $this->indexExists('dtr_certifications', 'uq_dtr_personnel_period')) {
            Schema::table('dtr_certifications', fn (Blueprint $table) => $table
                ->unique(
                    ['personnel_id', 'dtr_year', 'dtr_month', 'dtr_period'],
                    'uq_dtr_personnel_period'
                ));
        }

        if (! $this->indexExists('dtr_certifications', 'idx_dtr_period_status_personnel')) {
            Schema::table('dtr_certifications', fn (Blueprint $table) => $table
                ->index(
                    ['dtr_year', 'dtr_month', 'dtr_period', 'certification_status', 'personnel_id'],
                    'idx_dtr_period_status_personnel'
                ));
        }
    }

    public function down(): void
    {
        $duplicates = DB::table('dtr_certifications')
            ->selectRaw('personnel_id, dtr_year, dtr_month, COUNT(*) AS aggregate')
            ->groupBy('personnel_id', 'dtr_year', 'dtr_month')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicates) {
            throw new RuntimeException(
                'Cannot remove DTR reporting periods while multiple periods exist for a personnel month.'
            );
        }

        if (! $this->indexExists('dtr_certifications', 'uq_dtr_personnel_month')) {
            Schema::table('dtr_certifications', fn (Blueprint $table) => $table
                ->unique(['personnel_id', 'dtr_year', 'dtr_month'], 'uq_dtr_personnel_month'));
        }

        Schema::table('dtr_certifications', function (Blueprint $table): void {
            $table->dropIndex('idx_dtr_period_status_personnel');
            $table->dropUnique('uq_dtr_personnel_period');
            $table->dropColumn('dtr_period');
        });

        if ($this->indexExists('dtr_certifications', 'idx_dtr_personnel_fk')) {
            Schema::table('dtr_certifications', fn (Blueprint $table) => $table
                ->dropIndex('idx_dtr_personnel_fk'));
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return collect(DB::select("PRAGMA index_list('{$table}')"))
                ->contains(fn ($row) => $row->name === $index);
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
