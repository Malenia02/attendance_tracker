<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Holiday;
use App\Models\Personnel;
use App\Models\PersonnelSchedule;
use App\Models\TimeLog;
use App\Support\PersonnelAccess;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $canManageOthers = PersonnelAccess::canManageOthers($user);
        $hasGlobalAccess = PersonnelAccess::hasGlobalAccess($user);
        $today = now()->startOfDay();
        $weekStart = $today->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);
        $monthStart = $today->copy()->startOfMonth();
        $calendarEnd = $today->copy()->addDays(45);

        $calendarEvents = Holiday::query()
            ->with('department:department_id,department_code,department_name')
            ->whereBetween('holiday_date', [
                $weekStart->toDateString(),
                $calendarEnd->toDateString(),
            ])
            ->orderBy('holiday_date')
            ->get();
        $weekEvents = $calendarEvents->groupBy(
            fn (Holiday $holiday) => $holiday->holiday_date->toDateString()
        );

        $personnel = Personnel::query()
            ->with([
                'department:department_id,department_code,department_name',
                'scheduleAssignments' => fn ($query) => $query
                    ->with('schedule')
                    ->whereDate('effective_from', '<=', $weekEnd)
                    ->where(fn ($query) => $query
                        ->whereNull('effective_to')
                        ->orWhereDate('effective_to', '>=', $weekStart))
                    ->orderByDesc('effective_from'),
                'attendanceRecords' => fn ($query) => $query
                    ->whereBetween('attendance_date', [
                        $weekStart->toDateString(),
                        $weekEnd->toDateString(),
                    ])
                    ->orderBy('attendance_date'),
                'dtrCertifications' => fn ($query) => $query
                    ->where('dtr_year', $monthStart->year)
                    ->where('dtr_month', $monthStart->month),
            ])
            ->where('status', 'Active')
            ->tap(fn ($query) => PersonnelAccess::scope($query, $user))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $dailyRows = collect();
        $weekly = collect();

        foreach (CarbonPeriod::create($weekStart, $weekEnd) as $date) {
            $dateKey = $date->toDateString();
            $rows = $personnel->map(
                fn (Personnel $person) => $this->buildDailyRow(
                    $person,
                    $date,
                    $weekEvents->get($dateKey, collect())
                )
            );

            if ($date->isSameDay($today)) {
                $dailyRows = $rows;
            }

            $weekly->push([
                'date' => $dateKey,
                'day' => $date->format('D'),
                'day_number' => $date->day,
                'is_future' => $date->greaterThan($today),
                'expected' => $rows->where('is_duty_day', true)->count(),
                'present' => $date->greaterThan($today)
                    ? 0
                    : $rows->whereIn('status', ['Present', 'Half Day'])->count(),
                'late' => $date->greaterThan($today)
                    ? 0
                    : $rows->where('is_late', true)->count(),
                'incomplete' => $date->greaterThan($today)
                    ? 0
                    : $rows->whereIn('status', ['Incomplete', 'Not Started'])->count(),
                'calendar_label' => $this->calendarLabel(
                    $weekEvents->get($dateKey, collect()),
                    $rows
                ),
            ]);
        }

        $expectedToday = $dailyRows->where('is_duty_day', true)->count();
        $presentToday = $dailyRows->whereIn('status', ['Present', 'Half Day'])->count();
        $timedInToday = $dailyRows->filter(fn (array $row) => $row['has_time_entry'])->count();
        $lateToday = $dailyRows->where('is_late', true)->count();
        $exceptions = $dailyRows
            ->filter(fn (array $row) => $row['is_duty_day']
                && ($row['is_late']
                    || in_array($row['status'], ['Half Day', 'Incomplete', 'Absent', 'Not Started'], true)))
            ->sortBy(fn (array $row) => match ($row['status']) {
                'Incomplete' => 1,
                'Absent' => 2,
                'Half Day' => 3,
                'Not Started' => 4,
                default => 5,
            })
            ->take(6)
            ->values();

        $dtrStatuses = $personnel
            ->map(fn (Personnel $person) => $person->dtrCertifications->first()?->certification_status ?? 'Draft')
            ->countBy();
        $personnelIds = $personnel->pluck('personnel_id');
        $recentLogs = TimeLog::query()
            ->with('personnel:personnel_id,first_name,middle_name,last_name,suffix,employee_number')
            ->when($personnelIds->isNotEmpty(), fn ($query) => $query->whereIn('personnel_id', $personnelIds))
            ->when($personnelIds->isEmpty(), fn ($query) => $query->whereRaw('1 = 0'))
            ->latest('log_datetime')
            ->limit(7)
            ->get()
            ->map(fn (TimeLog $log) => [
                'id' => $log->time_log_id,
                'personnel_id' => $log->personnel_id,
                'full_name' => $log->personnel?->full_name ?? 'Unknown personnel',
                'employee_number' => $log->personnel?->employee_number,
                'action' => $log->log_type,
                'source' => $log->log_source,
                'logged_at' => $log->log_datetime->toISOString(),
            ]);
        $visibleDepartmentIds = $personnel->pluck('department_id')->filter()->unique();
        $upcomingEvents = $calendarEvents
            ->filter(fn (Holiday $event) => $event->holiday_date->betweenIncluded($today, $calendarEnd))
            ->filter(fn (Holiday $event) => ! $event->department_id
                || $visibleDepartmentIds->contains($event->department_id))
            ->take(5)
            ->map(fn (Holiday $event) => [
                'id' => $event->holiday_id,
                'date' => $event->holiday_date->toDateString(),
                'name' => $event->holiday_name,
                'type' => $event->holiday_type,
                'is_working_day' => $event->holiday_type === 'Special Working Holiday',
                'scope' => $event->department?->department_code ?? 'All offices',
            ])
            ->values();
        $departments = $personnel
            ->groupBy(fn (Personnel $person) => $person->department?->department_code ?? 'Unassigned')
            ->map(fn (Collection $members, string $code) => [
                'code' => $code,
                'name' => $members->first()?->department?->department_name ?? 'Unassigned personnel',
                'count' => $members->count(),
            ])
            ->sortByDesc('count')
            ->values()
            ->take(6);

        return response()->json([
            'server_time' => now()->toISOString(),
            'timezone' => config('app.timezone'),
            'scope' => $hasGlobalAccess
                ? 'All active personnel'
                : ($canManageOthers ? 'My assigned office' : 'My attendance'),
            'today' => [
                'date' => $today->toDateString(),
                'day_label' => $today->format('l, F j, Y'),
                'total_personnel' => $personnel->count(),
                'expected' => $expectedToday,
                'present' => $presentToday,
                'timed_in' => $timedInToday,
                'late' => $lateToday,
                'half_day' => $dailyRows->where('status', 'Half Day')->count(),
                'incomplete' => $dailyRows->where('status', 'Incomplete')->count(),
                'not_started' => $dailyRows->where('status', 'Not Started')->count(),
                'attendance_rate' => $expectedToday
                    ? (int) round(($presentToday / $expectedToday) * 100)
                    : 100,
                'calendar_status' => $this->calendarLabel(
                    $weekEvents->get($today->toDateString(), collect()),
                    $dailyRows
                ),
            ],
            'weekly' => $weekly,
            'dtr' => [
                'month' => $monthStart->format('Y-m'),
                'month_label' => $monthStart->format('F Y'),
                'draft' => (int) ($dtrStatuses['Draft'] ?? 0),
                'submitted' => (int) ($dtrStatuses['Submitted'] ?? 0),
                'returned' => (int) ($dtrStatuses['Returned'] ?? 0),
                'certified' => (int) ($dtrStatuses['Certified'] ?? 0),
                'total' => $personnel->count(),
            ],
            'exceptions' => $exceptions,
            'upcoming_events' => $upcomingEvents,
            'departments' => $departments,
            'recent_logs' => $recentLogs,
        ]);
    }

    private function buildDailyRow(
        Personnel $person,
        Carbon $date,
        Collection $events
    ): array {
        $assignment = $person->scheduleAssignments->first(fn (PersonnelSchedule $assignment) => $assignment->effective_from->lte($date)
            && (! $assignment->effective_to || $assignment->effective_to->gte($date))
        );
        $schedule = $assignment?->schedule;
        $record = $person->attendanceRecords->first(
            fn (AttendanceRecord $record) => $record->attendance_date->isSameDay($date)
        );
        $applies = fn (Holiday $event) => ! $event->department_id
            || $event->department_id === $person->department_id;
        $holiday = $events->first(fn (Holiday $event) => $event->holiday_type !== 'Special Working Holiday' && $applies($event)
        );
        $isAuthorizedDuty = $events->contains(fn (Holiday $event) => $event->holiday_type === 'Special Working Holiday' && $applies($event)
        );
        $dayField = strtolower($date->format('l'));
        $isDutyDay = ((bool) ($schedule?->{$dayField}) || $isAuthorizedDuty) && ! $holiday;
        $status = $holiday
            ? 'Holiday'
            : (! $isDutyDay
                ? 'Rest Day'
                : ($record?->attendance_status ?? 'Not Started'));

        return [
            'personnel_id' => $person->personnel_id,
            'employee_number' => $person->employee_number,
            'full_name' => $person->full_name,
            'department' => $person->department?->department_code ?? 'No office',
            'status' => $status,
            'is_duty_day' => $isDutyDay,
            'is_authorized_duty_day' => $isAuthorizedDuty && ! (bool) ($schedule?->{$dayField}),
            'has_time_entry' => (bool) ($record?->morning_time_in || $record?->afternoon_time_in),
            'is_late' => (int) ($record?->late_minutes ?? 0) > 0,
            'late_minutes' => (int) ($record?->late_minutes ?? 0),
        ];
    }

    private function calendarLabel(Collection $events, Collection $rows): string
    {
        $workingEvent = $events->firstWhere('holiday_type', 'Special Working Holiday');

        if ($workingEvent) {
            return 'Authorized duty day';
        }

        $holiday = $events->first(fn (Holiday $event) => $event->holiday_type !== 'Special Working Holiday'
        );

        if ($holiday) {
            return $holiday->holiday_name;
        }

        return $rows->where('is_duty_day', true)->isNotEmpty()
            ? 'Regular duty day'
            : 'Rest day';
    }
}
