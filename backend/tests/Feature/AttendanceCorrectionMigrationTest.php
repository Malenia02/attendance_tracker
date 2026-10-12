<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AttendanceCorrectionMigrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('attendance_correction_requests');
        Schema::dropIfExists('work_schedules');
        Schema::dropIfExists('attendance_records');

        parent::tearDown();
    }

    public function test_upgrade_handles_a_custom_attendance_foreign_key_name_and_preserves_requests(): void
    {
        $this->createPreviousSchema(withAttendanceForeignKey: true);

        $this->runUpgrade();

        $this->assertUpgradedRequestIsPreserved();
        $this->assertForeignKeyExists('attendance_id');
        $this->assertForeignKeyExists('schedule_id');
    }

    public function test_upgrade_restores_a_missing_attendance_foreign_key(): void
    {
        $this->createPreviousSchema(withAttendanceForeignKey: false);

        $this->runUpgrade();

        $this->assertUpgradedRequestIsPreserved();
        $this->assertForeignKeyExists('attendance_id');
        $this->assertForeignKeyExists('schedule_id');
    }

    public function test_upgrade_stops_before_schema_changes_when_existing_attendance_reference_is_orphaned(): void
    {
        $this->createPreviousSchema(withAttendanceForeignKey: false);
        DB::table('attendance_correction_requests')->update(['attendance_id' => 999]);

        try {
            $this->runUpgrade();
            $this->fail('The upgrade should reject an orphaned attendance reference.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('reference missing records', $exception->getMessage());
        }

        $this->assertFalse(Schema::hasColumn('attendance_correction_requests', 'schedule_id'));
        $this->assertSame(999, DB::table('attendance_correction_requests')->value('attendance_id'));
    }

    private function createPreviousSchema(bool $withAttendanceForeignKey): void
    {
        Schema::create('attendance_records', function (Blueprint $table): void {
            $table->bigIncrements('attendance_id');
        });
        Schema::create('work_schedules', function (Blueprint $table): void {
            $table->bigIncrements('schedule_id');
        });
        Schema::create('attendance_correction_requests', function (Blueprint $table) use ($withAttendanceForeignKey): void {
            $table->bigIncrements('attendance_correction_request_id');
            $table->unsignedBigInteger('attendance_id');
            $table->unsignedBigInteger('personnel_id');
            $table->date('attendance_date');
            $table->enum('missing_field', ['morning_time_out', 'afternoon_time_out']);
            $table->time('proposed_time');
            $table->string('request_status')->default('Pending');
            $table->timestamps();

            if ($withAttendanceForeignKey) {
                $table->foreign('attendance_id', 'custom_attendance_record_reference')
                    ->references('attendance_id')
                    ->on('attendance_records');
            }
        });

        DB::table('attendance_records')->insert(['attendance_id' => 12]);
        DB::table('attendance_correction_requests')->insert([
            'attendance_correction_request_id' => 34,
            'attendance_id' => 12,
            'personnel_id' => 56,
            'attendance_date' => '2026-08-03',
            'missing_field' => 'morning_time_out',
            'proposed_time' => '12:05:00',
            'request_status' => 'Pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function runUpgrade(): void
    {
        $migration = require database_path(
            'migrations/2026_10_12_000000_allow_time_in_attendance_corrections.php'
        );

        $migration->up();
    }

    private function assertUpgradedRequestIsPreserved(): void
    {
        $this->assertDatabaseHas('attendance_correction_requests', [
            'attendance_correction_request_id' => 34,
            'attendance_id' => 12,
            'personnel_id' => 56,
            'attendance_date' => '2026-08-03',
            'missing_field' => 'morning_time_out',
            'proposed_time' => '12:05:00',
            'request_status' => 'Pending',
        ]);

        $attendanceId = collect(Schema::getColumns('attendance_correction_requests'))
            ->firstWhere('name', 'attendance_id');
        $this->assertTrue($attendanceId['nullable']);
        $this->assertTrue(Schema::hasColumn('attendance_correction_requests', 'schedule_id'));
    }

    private function assertForeignKeyExists(string $column): void
    {
        $hasForeignKey = collect(Schema::getForeignKeys('attendance_correction_requests'))
            ->contains(fn (array $foreignKey): bool => ($foreignKey['columns'] ?? []) === [$column]);

        $this->assertTrue($hasForeignKey, "Expected a foreign key for {$column}.");
    }
}
