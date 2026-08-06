<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\DtrCertification;
use App\Models\Holiday;
use App\Models\LeaveRecord;
use App\Models\Personnel;
use App\Models\PersonnelSchedule;
use App\Models\User;
use App\Support\DtrPeriod;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class LeaveAttendanceService
{
    public function eligibleDates(
        Personnel $personnel,
        Carbon $start,
        Carbon $end
    ): Collection {
        $personnel->load([
            'scheduleAssignments' => fn ($query) => $query
                ->with('schedule')
                ->whereDate('effective_from', '<=', $end->toDateString())
                ->where(fn ($query) => $query
                    ->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $start->toDateString()))
                ->orderByDesc('effective_from'),
        ]);
        $holidays = Holiday::query()
            ->whereBetween('holiday_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->groupBy(fn (Holiday $holiday) => $holiday->holiday_date->toDateString());
        $eligible = collect();

        foreach (CarbonPeriod::create($start, $end) as $date) {
            if ($personnel->employment_start_date
                && $date->lessThan($personnel->employment_start_date)) {
                continue;
            }

            if ($personnel->employment_end_date
                && $date->greaterThan($personnel->employment_end_date)) {
                continue;
            }

            $assignment = $personnel->scheduleAssignments->first(
                fn (PersonnelSchedule $assignment): bool => $assignment->effective_from->lte($date)
                    && (! $assignment->effective_to || $assignment->effective_to->gte($date))
                    && $assignment->schedule?->status === 'Active'
            );
            $schedule = $assignment?->schedule;

            if (! $schedule) {
                continue;
            }

            $events = $holidays->get($date->toDateString(), collect());
            $applies = fn (Holiday $holiday): bool => ! $holiday->department_id
                || (int) $holiday->department_id === (int) $personnel->department_id;
            $nonWorkingHoliday = $events->contains(
                fn (Holiday $holiday): bool => $holiday->holiday_type !== 'Special Working Holiday'
                    && $applies($holiday)
            );
            $specialWorkingDay = $events->contains(
                fn (Holiday $holiday): bool => $holiday->holiday_type === 'Special Working Holiday'
                    && $applies($holiday)
            );
            $dayField = strtolower($date->format('l'));

            if (! $nonWorkingHoliday && ($schedule->{$dayField} || $specialWorkingDay)) {
                $eligible->push([
                    'date' => $date->toDateString(),
                    'schedule_id' => $schedule->schedule_id,
                ]);
            }
        }

        return $eligible;
    }

    public function syncApproved(LeaveRecord $leaveRecord, User $reviewer): int
    {
        $leaveRecord->loadMissing('personnel');
        $start = $leaveRecord->date_from->copy()->startOfDay();
        $end = $leaveRecord->date_to->copy()->startOfDay();
        $this->assertDtrEditable($leaveRecord->personnel_id, $start, $end);
        $eligible = $this->eligibleDates($leaveRecord->personnel, $start, $end);

        if ($eligible->isEmpty()) {
            throw ValidationException::withMessages([
                'date_from' => ['The selected range contains no scheduled duty days.'],
            ]);
        }

        $existing = AttendanceRecord::query()
            ->where('personnel_id', $leaveRecord->personnel_id)
            ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy(fn (AttendanceRecord $record) => $record->attendance_date->toDateString());
        $conflicts = $eligible
            ->filter(function (array $day) use ($existing, $leaveRecord): bool {
                $record = $existing->get($day['date']);

                return $record && (int) $record->leave_record_id !== (int) $leaveRecord->leave_id;
            })
            ->pluck('date')
            ->values();

        if ($conflicts->isNotEmpty()) {
            throw ValidationException::withMessages([
                'date_from' => [
                    'Attendance already exists for '.$conflicts->take(3)->implode(', ')
                    .($conflicts->count() > 3 ? ' and additional dates.' : '.'),
                ],
            ]);
        }

        $now = now();
        $attendanceStatus = $leaveRecord->leave_type === 'Official Business'
            ? 'Official Business'
            : 'Leave';
        $rows = $eligible->map(fn (array $day): array => [
            'personnel_id' => $leaveRecord->personnel_id,
            'schedule_id' => $day['schedule_id'],
            'leave_record_id' => $leaveRecord->leave_id,
            'attendance_date' => $day['date'],
            'attendance_status' => $attendanceStatus,
            'total_work_minutes' => 0,
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'overtime_minutes' => 0,
            'remarks' => "Approved {$leaveRecord->leave_type} request {$leaveRecord->request_number}.",
            'record_source' => 'System',
            'is_verified' => true,
            'verified_by' => $reviewer->user_id,
            'verified_at' => $now,
            'created_by' => $reviewer->user_id,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        AttendanceRecord::query()->upsert(
            $rows,
            ['personnel_id', 'attendance_date'],
            [
                'schedule_id',
                'leave_record_id',
                'attendance_status',
                'total_work_minutes',
                'late_minutes',
                'undertime_minutes',
                'overtime_minutes',
                'remarks',
                'record_source',
                'is_verified',
                'verified_by',
                'verified_at',
                'updated_at',
            ]
        );

        return count($rows);
    }

    public function removeGeneratedAttendance(LeaveRecord $leaveRecord): int
    {
        $this->assertDtrEditable(
            $leaveRecord->personnel_id,
            $leaveRecord->date_from,
            $leaveRecord->date_to
        );

        return AttendanceRecord::query()
            ->where('leave_record_id', $leaveRecord->leave_id)
            ->delete();
    }

    private function assertDtrEditable(
        int $personnelId,
        Carbon $start,
        Carbon $end
    ): void {
        $months = collect(CarbonPeriod::create(
            $start->copy()->startOfMonth(),
            '1 month',
            $end->copy()->startOfMonth()
        ))->map(function (Carbon $month) use ($start, $end): array {
            $rangeStart = $start->greaterThan($month) ? $start : $month->copy()->startOfMonth();
            $monthEnd = $month->copy()->endOfMonth();
            $rangeEnd = $end->lessThan($monthEnd) ? $end : $monthEnd;
            $periods = [DtrPeriod::FULL_MONTH];

            if ($rangeStart->day <= 15) {
                $periods[] = DtrPeriod::FIRST_HALF;
            }

            if ($rangeEnd->day >= 16) {
                $periods[] = DtrPeriod::SECOND_HALF;
            }

            return [$month->year, $month->month, $periods];
        });
        $locked = DtrCertification::query()
            ->where('personnel_id', $personnelId)
            ->whereIn('certification_status', ['Submitted', 'Certified'])
            ->where(function ($query) use ($months): void {
                foreach ($months as [$year, $month, $periods]) {
                    $query->orWhere(fn ($query) => $query
                        ->where('dtr_year', $year)
                        ->where('dtr_month', $month)
                        ->whereIn('dtr_period', $periods));
                }
            })
            ->exists();

        if ($locked) {
            throw ValidationException::withMessages([
                'date_from' => [
                    'This period belongs to a submitted or certified DTR. Reopen the DTR before changing the request.',
                ],
            ]);
        }
    }
}
