<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = DB::connection();
if ($connection->getDriverName() !== 'mysql') {
    throw new RuntimeException('This migration verification must run against MySQL.');
}

if ($connection->getDatabaseName() !== 'attendance_migration_test') {
    throw new RuntimeException('Refusing to run outside the dedicated attendance_migration_test database.');
}

$tables = [
    'attendance_correction_requests',
    'work_schedules',
    'attendance_records',
];

foreach ($tables as $table) {
    if (Schema::hasTable($table)) {
        throw new RuntimeException("Refusing to overwrite existing table: {$table}.");
    }
}

try {
    Schema::create('attendance_records', function (Blueprint $table): void {
        $table->bigIncrements('attendance_id');
    });

    Schema::create('work_schedules', function (Blueprint $table): void {
        $table->increments('schedule_id');
    });

    Schema::create('attendance_correction_requests', function (Blueprint $table): void {
        $table->bigIncrements('attendance_correction_request_id');
        $table->unsignedBigInteger('attendance_id')->nullable();
        // This reproduces the BIGINT left by the failed production migration.
        $table->unsignedBigInteger('schedule_id')->nullable();
        $table->enum('missing_field', [
            'morning_time_in',
            'morning_time_out',
            'afternoon_time_in',
            'afternoon_time_out',
        ]);
        $table->foreign('attendance_id', 'legacy_attendance_record_reference')
            ->references('attendance_id')
            ->on('attendance_records');
    });

    DB::table('attendance_records')->insert(['attendance_id' => 12]);
    DB::table('work_schedules')->insert(['schedule_id' => 7]);
    DB::table('attendance_correction_requests')->insert([
        'attendance_correction_request_id' => 34,
        'attendance_id' => 12,
        'schedule_id' => 7,
        'missing_field' => 'morning_time_out',
    ]);

    $migration = require dirname(__DIR__, 2)
        .'/database/migrations/2026_10_12_000000_allow_time_in_attendance_corrections.php';
    $migration->up();

    $scheduleColumn = DB::selectOne(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS '
        .'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$connection->getDatabaseName(), 'attendance_correction_requests', 'schedule_id']
    );

    if (strtolower((string) $scheduleColumn?->COLUMN_TYPE) !== 'int unsigned') {
        throw new RuntimeException('The schedule_id column was not normalized to INT UNSIGNED.');
    }

    foreach ([
        'attendance_id' => 'attendance_records',
        'schedule_id' => 'work_schedules',
    ] as $column => $referencedTable) {
        $foreignKeyExists = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', $connection->getDatabaseName())
            ->where('TABLE_NAME', 'attendance_correction_requests')
            ->where('COLUMN_NAME', $column)
            ->where('REFERENCED_TABLE_NAME', $referencedTable)
            ->where('REFERENCED_COLUMN_NAME', $column)
            ->exists();

        if (! $foreignKeyExists) {
            throw new RuntimeException("The {$column} foreign key was not created.");
        }
    }

    $preservedRequest = DB::table('attendance_correction_requests')
        ->where('attendance_correction_request_id', 34)
        ->where('attendance_id', 12)
        ->where('schedule_id', 7)
        ->where('missing_field', 'morning_time_out')
        ->exists();

    if (! $preservedRequest) {
        throw new RuntimeException('The existing correction request was not preserved.');
    }

    fwrite(STDOUT, "Partial MySQL migration repair passed; schedule_id data and foreign key are intact.\n");
} finally {
    foreach ($tables as $table) {
        Schema::dropIfExists($table);
    }
}
