<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('personnel', 'qr_valid_from')) {
            Schema::table('personnel', function (Blueprint $table): void {
                $table->date('qr_valid_from')->nullable()->after('qr_login_code');
            });
        }

        if (! Schema::hasColumn('personnel', 'qr_valid_until')) {
            Schema::table('personnel', function (Blueprint $table): void {
                $table->date('qr_valid_until')->nullable()->after('qr_valid_from');
            });
        }

        if (! $this->indexExists('idx_personnel_qr_validity')) {
            Schema::table('personnel', function (Blueprint $table): void {
                $table->index(
                    ['qr_valid_from', 'qr_valid_until'],
                    'idx_personnel_qr_validity'
                );
            });
        }

        $today = today();

        DB::table('personnel')
            ->where(function ($query): void {
                $query->whereNull('qr_valid_from')
                    ->orWhereNull('qr_valid_until');
            })
            ->select([
                'personnel_id',
                'employment_start_date',
                'employment_end_date',
            ])
            ->orderBy('personnel_id')
            ->chunkById(100, function ($personnel) use ($today): void {
                foreach ($personnel as $person) {
                    $employmentStart = $person->employment_start_date
                        ? Carbon::parse($person->employment_start_date)->startOfDay()
                        : null;
                    $employmentEnd = $person->employment_end_date
                        ? Carbon::parse($person->employment_end_date)->startOfDay()
                        : null;

                    if ($employmentEnd?->lt($today)) {
                        $validFrom = $employmentStart && $employmentStart->lte($employmentEnd)
                            ? $employmentStart
                            : $employmentEnd;
                        $validUntil = $employmentEnd;
                    } else {
                        $validFrom = $employmentStart?->gt($today)
                            ? $employmentStart
                            : $today->copy();
                        $validUntil = $validFrom->copy()->addYear()->subDay();

                        if ($employmentEnd && $employmentEnd->lt($validUntil)) {
                            $validUntil = $employmentEnd;
                        }
                    }

                    DB::table('personnel')
                        ->where('personnel_id', $person->personnel_id)
                        ->update([
                            'qr_valid_from' => $validFrom->toDateString(),
                            'qr_valid_until' => $validUntil->toDateString(),
                        ]);
                }
            }, 'personnel_id');

        if (DB::getDriverName() === 'mysql') {
            $this->addCheckIfMissing(
                'chk_personnel_qr_validity_dates',
                '`qr_valid_until` IS NULL OR `qr_valid_from` IS NULL '
                .'OR `qr_valid_until` >= `qr_valid_from`'
            );
            $this->addCheckIfMissing(
                'chk_personnel_qr_after_employment_start',
                '`employment_start_date` IS NULL OR `qr_valid_from` IS NULL '
                .'OR `qr_valid_from` >= `employment_start_date`'
            );
            $this->addCheckIfMissing(
                'chk_personnel_qr_before_employment_end',
                '`employment_end_date` IS NULL OR `qr_valid_until` IS NULL '
                .'OR `qr_valid_until` <= `employment_end_date`'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            foreach ([
                'chk_personnel_qr_validity_dates',
                'chk_personnel_qr_after_employment_start',
                'chk_personnel_qr_before_employment_end',
            ] as $constraint) {
                if ($this->checkExists($constraint)) {
                    DB::statement(
                        "ALTER TABLE `personnel` DROP CHECK `{$constraint}`"
                    );
                }
            }
        }

        Schema::table('personnel', function (Blueprint $table): void {
            if ($this->indexExists('idx_personnel_qr_validity')) {
                $table->dropIndex('idx_personnel_qr_validity');
            }
            $table->dropColumn(['qr_valid_from', 'qr_valid_until']);
        });
    }

    private function indexExists(string $indexName): bool
    {
        return collect(Schema::getIndexes('personnel'))
            ->contains(fn (array $index): bool => ($index['name'] ?? null) === $indexName);
    }

    private function addCheckIfMissing(string $name, string $expression): void
    {
        if ($this->checkExists($name)) {
            return;
        }

        DB::statement(
            "ALTER TABLE `personnel` ADD CONSTRAINT `{$name}` CHECK ({$expression})"
        );
    }

    private function checkExists(string $name): bool
    {
        return DB::selectOne(
            'SELECT 1 AS present '
            .'FROM information_schema.TABLE_CONSTRAINTS '
            .'WHERE CONSTRAINT_SCHEMA = DATABASE() '
            ."AND TABLE_NAME = 'personnel' "
            .'AND CONSTRAINT_NAME = ? '
            ."AND CONSTRAINT_TYPE = 'CHECK' "
            .'LIMIT 1',
            [$name]
        ) !== null;
    }
};
