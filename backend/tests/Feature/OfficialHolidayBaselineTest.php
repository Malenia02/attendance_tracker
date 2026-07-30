<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OfficialHolidayBaselineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('holidays', function (Blueprint $table): void {
            $table->increments('holiday_id');
            $table->date('holiday_date');
            $table->string('holiday_name', 150);
            $table->string('holiday_type');
            $table->string('scope')->default('National');
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('description')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(
                ['holiday_date', 'scope', 'holiday_type'],
                'uq_test_holiday_date_scope_type'
            );
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('holidays');

        parent::tearDown();
    }

    public function test_official_2026_calendar_is_complete_and_idempotent(): void
    {
        $migration = require database_path(
            'migrations/2026_07_30_040000_seed_2026_national_holidays.php'
        );

        $migration->up();
        $migration->up();

        $this->assertDatabaseCount('holidays', 21);
        $this->assertSame(
            12,
            DB::table('holidays')
                ->where('holiday_type', 'Regular Holiday')
                ->count()
        );
        $this->assertSame(
            8,
            DB::table('holidays')
                ->where('holiday_type', 'Special Non-Working Holiday')
                ->count()
        );
        $this->assertDatabaseHas('holidays', [
            'holiday_date' => '2026-02-17',
            'holiday_name' => 'Chinese New Year',
            'holiday_type' => 'Special Non-Working Holiday',
        ]);
        $this->assertDatabaseHas('holidays', [
            'holiday_date' => '2026-02-25',
            'holiday_name' => 'EDSA People Power Revolution Anniversary',
            'holiday_type' => 'Special Working Holiday',
        ]);
        $this->assertDatabaseHas('holidays', [
            'holiday_date' => '2026-03-20',
            'holiday_name' => "Eid'l Fitr (Feast of Ramadhan)",
            'holiday_type' => 'Regular Holiday',
        ]);
        $this->assertDatabaseHas('holidays', [
            'holiday_date' => '2026-05-27',
            'holiday_name' => "Eid'l Adha (Feast of Sacrifice)",
            'holiday_type' => 'Regular Holiday',
        ]);
    }

    public function test_rollback_keeps_manually_created_records(): void
    {
        $migration = require database_path(
            'migrations/2026_07_30_040000_seed_2026_national_holidays.php'
        );
        $migration->up();
        DB::table('holidays')->insert([
            'holiday_date' => '2026-07-30',
            'holiday_name' => 'Office Anniversary',
            'holiday_type' => 'Local Holiday',
            'scope' => 'Office',
            'description' => 'Created by an administrator.',
            'created_by' => 99,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->down();

        $this->assertDatabaseCount('holidays', 1);
        $this->assertDatabaseHas('holidays', [
            'holiday_name' => 'Office Anniversary',
            'created_by' => 99,
        ]);
    }
}
