<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\AttendanceController;
use App\Models\AttendanceRecord;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use ReflectionMethod;
use Tests\TestCase;

class AttendanceEligibilityTest extends TestCase
{
    private WorkSchedule $schedule;

    private ReflectionMethod $eligibility;

    private ReflectionMethod $attendanceStatus;

    private ReflectionMethod $recalculate;

    private ReflectionMethod $missingTimeOutEntries;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schedule = new WorkSchedule([
            'morning_start' => '07:00:00',
            'morning_end' => '12:00:00',
            'afternoon_start' => '13:00:00',
            'afternoon_end' => '17:00:00',
            'morning_time_in_start' => '06:00:00',
            'morning_time_in_end' => '09:00:00',
            'morning_time_out_start' => '11:30:00',
            'morning_time_out_end' => '12:30:00',
            'afternoon_time_in_start' => '12:30:00',
            'afternoon_time_in_end' => '14:00:00',
            'afternoon_time_out_start' => '16:30:00',
            'afternoon_time_out_end' => '19:00:00',
            'grace_period_minutes' => 0,
            'monday' => true,
            'tuesday' => true,
            'wednesday' => true,
            'thursday' => true,
            'friday' => false,
            'saturday' => false,
            'sunday' => false,
        ]);

        $this->eligibility = new ReflectionMethod(
            AttendanceController::class,
            'determineAvailableAction'
        );
        $this->attendanceStatus = new ReflectionMethod(
            AttendanceController::class,
            'determineAttendanceStatus'
        );
        $this->recalculate = new ReflectionMethod(
            AttendanceController::class,
            'recalculate'
        );
        $this->missingTimeOutEntries = new ReflectionMethod(
            AttendanceController::class,
            'missingTimeOutEntries'
        );
    }

    public function test_morning_time_in_is_only_offered_during_its_window(): void
    {
        $result = $this->check(null, '2026-07-23 08:15:00');

        $this->assertSame('morning_time_in', $result['action']);
    }

    public function test_afternoon_time_in_is_offered_when_morning_was_missed(): void
    {
        $result = $this->check(null, '2026-07-23 13:15:00');

        $this->assertSame('afternoon_time_in', $result['action']);
    }

    public function test_late_morning_arrival_can_still_time_in_before_morning_closes(): void
    {
        $result = $this->check(null, '2026-07-23 11:45:00');

        $this->assertSame('morning_time_in', $result['action']);
    }

    public function test_late_arrival_can_time_out_after_recording_morning_time_in(): void
    {
        $record = new AttendanceRecord([
            'morning_time_in' => '2026-07-23 11:45:00',
            'attendance_status' => 'Incomplete',
        ]);

        $result = $this->check($record, '2026-07-23 11:46:00');

        $this->assertSame('morning_time_out', $result['action']);
    }

    public function test_arrival_after_seven_is_flagged_with_late_minutes(): void
    {
        $record = new AttendanceRecord([
            'attendance_date' => '2026-07-23',
            'morning_time_in' => '2026-07-23 07:35:00',
            'attendance_status' => 'Incomplete',
        ]);

        $this->recalculate->invoke(
            new AttendanceController,
            $record,
            $this->schedule,
            Carbon::parse('2026-07-23 07:35:00', 'Asia/Manila')
        );

        $this->assertSame(35, $record->late_minutes);
    }

    public function test_morning_time_in_cannot_be_recorded_in_the_afternoon(): void
    {
        $result = $this->check(null, '2026-07-23 15:00:00');

        $this->assertNull($result['action']);
        $this->assertStringNotContainsString('Morning Time In is available', $result['message']);
    }

    public function test_optional_day_is_rejected_without_working_day_approval(): void
    {
        $result = $this->check(null, '2026-07-25 07:00:00');

        $this->assertNull($result['action']);
        $this->assertStringContainsString('not enabled as a duty day', $result['message']);
    }

    public function test_optional_day_is_enabled_by_working_day_approval(): void
    {
        $result = $this->eligibility->invoke(
            new AttendanceController,
            null,
            $this->schedule,
            Carbon::parse('2026-07-25 07:00:00', 'Asia/Manila'),
            true
        );

        $this->assertSame('morning_time_in', $result['action']);
    }

    public function test_afternoon_time_out_requires_afternoon_time_in(): void
    {
        $result = $this->check(null, '2026-07-23 17:00:00');

        $this->assertNull($result['action']);
        $this->assertStringContainsString('requires a matching time-in', $result['message']);
    }

    public function test_afternoon_time_out_is_offered_after_afternoon_time_in(): void
    {
        $record = new AttendanceRecord([
            'afternoon_time_in' => '2026-07-23 13:15:00',
            'attendance_status' => 'Incomplete',
        ]);

        $result = $this->check($record, '2026-07-23 17:00:00');

        $this->assertSame('afternoon_time_out', $result['action']);
    }

    public function test_completed_morning_session_becomes_half_day_after_afternoon_cutoff(): void
    {
        $record = new AttendanceRecord([
            'morning_time_in' => '2026-07-23 08:00:00',
            'morning_time_out' => '2026-07-23 12:00:00',
            'attendance_status' => 'Incomplete',
        ]);

        $status = $this->checkStatus($record, '2026-07-23 15:00:00');

        $this->assertSame('Half Day', $status);
    }

    public function test_completed_morning_session_is_not_half_day_while_afternoon_time_in_is_open(): void
    {
        $record = new AttendanceRecord([
            'morning_time_in' => '2026-07-23 08:00:00',
            'morning_time_out' => '2026-07-23 12:00:00',
            'attendance_status' => 'Incomplete',
        ]);

        $status = $this->checkStatus($record, '2026-07-23 13:15:00');

        $this->assertSame('Incomplete', $status);
    }

    public function test_completed_afternoon_only_session_is_half_day(): void
    {
        $record = new AttendanceRecord([
            'afternoon_time_in' => '2026-07-23 13:00:00',
            'afternoon_time_out' => '2026-07-23 17:00:00',
            'attendance_status' => 'Incomplete',
        ]);

        $status = $this->checkStatus($record, '2026-07-23 17:00:00');

        $this->assertSame('Half Day', $status);
    }

    public function test_morning_time_out_is_not_flagged_before_its_window_closes(): void
    {
        $record = new AttendanceRecord([
            'attendance_date' => '2026-07-23',
            'morning_time_in' => '2026-07-23 07:05:00',
            'attendance_status' => 'Incomplete',
        ]);

        $missing = $this->checkMissingTimeOuts($record, '2026-07-23 12:15:00');

        $this->assertSame([], $missing);
    }

    public function test_forgotten_morning_time_out_is_flagged_after_its_window_closes(): void
    {
        $record = new AttendanceRecord([
            'attendance_date' => '2026-07-23',
            'morning_time_in' => '2026-07-23 07:05:00',
            'attendance_status' => 'Incomplete',
        ]);

        $missing = $this->checkMissingTimeOuts($record, '2026-07-23 12:31:00');

        $this->assertSame('morning_time_out', $missing[0]['field']);
        $this->assertSame('Morning time-out', $missing[0]['label']);
    }

    public function test_forgotten_afternoon_time_out_is_flagged_after_its_window_closes(): void
    {
        $record = new AttendanceRecord([
            'attendance_date' => '2026-07-23',
            'morning_time_in' => '2026-07-23 07:05:00',
            'morning_time_out' => '2026-07-23 12:00:00',
            'afternoon_time_in' => '2026-07-23 13:00:00',
            'attendance_status' => 'Incomplete',
        ]);

        $missing = $this->checkMissingTimeOuts($record, '2026-07-23 19:01:00');

        $this->assertCount(1, $missing);
        $this->assertSame('afternoon_time_out', $missing[0]['field']);
    }

    private function check(?AttendanceRecord $record, string $time): array
    {
        return $this->eligibility->invoke(
            new AttendanceController,
            $record,
            $this->schedule,
            Carbon::parse($time, 'Asia/Manila')
        );
    }

    private function checkStatus(AttendanceRecord $record, string $time): string
    {
        return $this->attendanceStatus->invoke(
            new AttendanceController,
            $record,
            $this->schedule,
            Carbon::parse($time, 'Asia/Manila')
        );
    }

    private function checkMissingTimeOuts(AttendanceRecord $record, string $time): array
    {
        return $this->missingTimeOutEntries->invoke(
            new AttendanceController,
            $record,
            $this->schedule,
            Carbon::parse($time, 'Asia/Manila')
        );
    }
}
