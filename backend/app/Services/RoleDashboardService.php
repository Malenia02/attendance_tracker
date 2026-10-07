<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\AttendanceCorrectionRequest;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\DtrCertification;
use App\Models\Holiday;
use App\Models\LeaveRecord;
use App\Models\Personnel;
use App\Models\PersonnelSchedule;
use App\Models\User;
use App\Support\DtrPeriod;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

final class RoleDashboardService
{
    public function __construct(
        private readonly DtrCutoffService $cutoffs,
        private readonly SystemHealthService $systemHealth
    ) {}

    public function for(User $user): array
    {
        $user->loadMissing('personnel.department');

        $payload = match ($user->user_role) {
            'Administrator' => $this->administrator(),
            'HR' => $this->humanResources(),
            'Supervisor' => $this->supervisor($user),
            'Encoder', 'Personnel' => $this->personal($user),
            default => throw new AuthorizationException('This account role has no dashboard access.'),
        };

        return [
            'view' => $payload['view'],
            'role' => $user->user_role,
            'server_time' => now()->toISOString(),
            'timezone' => config('app.timezone'),
            'scope' => $payload['scope'],
            'period' => [
                'month' => today()->format('Y-m'),
                'month_label' => today()->format('F Y'),
                'today' => today()->toDateString(),
                'today_label' => today()->format('l, F j, Y'),
            ],
            ...$payload['data'],
        ];
    }

    private function personal(User $user): array
    {
        $personnel = $user->personnel;

        if (! $personnel) {
            return [
                'view' => 'personal',
                'scope' => 'My attendance',
                'data' => $this->emptyPersonalData(),
            ];
        }

        $monthStart = today()->startOfMonth();
        $monthEnd = today()->endOfMonth();
        $attendanceQuery = AttendanceRecord::query()
            ->where('personnel_id', $personnel->personnel_id)
            ->whereBetween('attendance_date', [
                $monthStart->toDateString(),
                $monthEnd->toDateString(),
            ]);
        $totals = (clone $attendanceQuery)
            ->selectRaw('COUNT(*) as recorded_days')
            ->selectRaw("SUM(CASE WHEN attendance_status IN ('Present', 'Half Day') THEN 1 ELSE 0 END) as days_present")
            ->selectRaw('COALESCE(SUM(total_work_minutes), 0) as work_minutes')
            ->selectRaw('COALESCE(SUM(late_minutes), 0) as late_minutes')
            ->selectRaw('COALESCE(SUM(undertime_minutes), 0) as undertime_minutes')
            ->first();
        $todayRecord = AttendanceRecord::query()
            ->where('personnel_id', $personnel->personnel_id)
            ->whereDate('attendance_date', today())
            ->first();
        $assignment = PersonnelSchedule::query()
            ->with('schedule')
            ->where('personnel_id', $personnel->personnel_id)
            ->whereDate('effective_from', '<=', today())
            ->where(function (Builder $query): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', today());
            })
            ->whereHas('schedule', fn (Builder $query) => $query->where('status', 'Active'))
            ->latest('effective_from')
            ->first();
        $leaveSummary = LeaveRecord::query()
            ->where('personnel_id', $personnel->personnel_id)
            ->selectRaw("SUM(CASE WHEN approval_status = 'Pending' THEN 1 ELSE 0 END) as pending")
            ->selectRaw("SUM(CASE WHEN approval_status = 'Approved' THEN 1 ELSE 0 END) as approved")
            ->first();
        $recentLeaves = LeaveRecord::query()
            ->where('personnel_id', $personnel->personnel_id)
            ->latest('created_at')
            ->limit(5)
            ->get()
            ->map(fn (LeaveRecord $leave): array => [
                'id' => $leave->leave_id,
                'type' => $leave->leave_type,
                'date_from' => $leave->date_from->toDateString(),
                'date_to' => $leave->date_to->toDateString(),
                'status' => $leave->approval_status,
            ]);
        $dtrContext = $this->cutoffs->currentContext($personnel);
        $currentDtrPeriod = $dtrContext['period'];
        $dtr = DtrCertification::query()
            ->where('personnel_id', $personnel->personnel_id)
            ->where('dtr_year', $dtrContext['year'])
            ->where('dtr_month', $dtrContext['month_number'])
            ->whereIn('dtr_period', [$currentDtrPeriod, DtrPeriod::FULL_MONTH])
            ->orderByRaw(
                'CASE WHEN dtr_period = ? THEN 0 WHEN dtr_period = ? THEN 1 ELSE 2 END',
                [$currentDtrPeriod, DtrPeriod::FULL_MONTH]
            )
            ->latest('version_number')
            ->first();
        if ($dtr && $dtr->dtr_period !== $dtrContext['period']) {
            $dtrContext = $this->cutoffs->context($monthStart, $dtr->dtr_period);
        }
        $recentAttendance = (clone $attendanceQuery)
            ->latest('attendance_date')
            ->limit(7)
            ->get()
            ->map(fn (AttendanceRecord $record): array => [
                'id' => $record->attendance_id,
                'date' => $record->attendance_date->toDateString(),
                'status' => $record->attendance_status,
                'work_minutes' => $record->total_work_minutes,
                'late_minutes' => $record->late_minutes,
                'undertime_minutes' => $record->undertime_minutes,
                'verified' => $record->is_verified,
            ]);

        return [
            'view' => 'personal',
            'scope' => 'My attendance only',
            'data' => [
                'profile' => [
                    'linked' => true,
                    'full_name' => $personnel->full_name,
                    'employee_number' => $personnel->employee_number,
                    'department' => $personnel->department?->department_code ?? 'Unassigned',
                ],
                'employment' => $this->personalEmployment($personnel),
                'metrics' => [
                    'work_minutes' => (int) ($totals->work_minutes ?? 0),
                    'days_present' => (int) ($totals->days_present ?? 0),
                    'late_minutes' => (int) ($totals->late_minutes ?? 0),
                    'undertime_minutes' => (int) ($totals->undertime_minutes ?? 0),
                ],
                'today_attendance' => $this->formatTodayRecord($todayRecord, $assignment, $personnel),
                'schedule' => $this->formatSchedule($assignment),
                'leave' => [
                    'pending' => (int) ($leaveSummary->pending ?? 0),
                    'approved' => (int) ($leaveSummary->approved ?? 0),
                    'recent' => $recentLeaves,
                ],
                'dtr' => $this->dtrReadiness($personnel, $dtrContext, $dtr),
                'upcoming_calendar' => $this->upcomingCalendar(
                    $personnel->department_id,
                    $personnel->personnel_id
                ),
                'recent_attendance' => $recentAttendance,
            ],
        ];
    }

    private function supervisor(User $user): array
    {
        $department = $user->personnel?->department;

        if (! $department) {
            return [
                'view' => 'supervisor',
                'scope' => 'No office assignment',
                'data' => [
                    'department' => ['linked' => false],
                    'metrics' => $this->emptyOperationsMetrics(),
                    'queues' => $this->emptySupervisorQueues(),
                    'attendance_statuses' => [],
                    'pending_leave' => [],
                    'lifecycle' => $this->emptyLifecycleOverview(),
                    'workforce_today' => $this->emptyWorkforceBreakdown(),
                    'upcoming_calendar' => [],
                ],
            ];
        }

        $departmentId = (int) $department->department_id;
        $personnelScope = fn (Builder $query): Builder => $query
            ->where('department_id', $departmentId);
        $activePersonnel = Personnel::query()
            ->where('department_id', $departmentId)
            ->where('status', 'Active')
            ->count();
        $todayQuery = AttendanceRecord::query()
            ->whereDate('attendance_date', today())
            ->whereHas('personnel', $personnelScope);
        $todaySummary = (clone $todayQuery)
            ->selectRaw("SUM(CASE WHEN attendance_status IN ('Present', 'Half Day') THEN 1 ELSE 0 END) as present")
            ->selectRaw('SUM(CASE WHEN late_minutes > 0 THEN 1 ELSE 0 END) as late')
            ->selectRaw("SUM(CASE WHEN attendance_status = 'Incomplete' THEN 1 ELSE 0 END) as incomplete")
            ->first();
        $verificationCount = $this->attendanceVerificationQuery($departmentId)->count();
        $leaveCount = $this->leaveQueueQuery($departmentId)->count();
        $submittedDtrCount = $this->submittedDtrQueueQuery($departmentId)->count();
        $returnedDtrCount = $this->dtrQueueQuery('Returned', $departmentId)->count();
        $statuses = (clone $todayQuery)
            ->selectRaw('attendance_status, COUNT(*) as total')
            ->groupBy('attendance_status')
            ->pluck('total', 'attendance_status')
            ->map(fn ($total): int => (int) $total);
        $pendingLeave = $this->leaveQueueQuery($departmentId)
            ->with('personnel:personnel_id,first_name,middle_name,last_name,suffix,employee_number')
            ->oldest('created_at')
            ->limit(5)
            ->get()
            ->map(fn (LeaveRecord $leave): array => [
                'id' => $leave->leave_id,
                'full_name' => $leave->personnel?->full_name ?? 'Unknown personnel',
                'employee_number' => $leave->personnel?->employee_number,
                'type' => $leave->leave_type,
                'date_from' => $leave->date_from->toDateString(),
                'date_to' => $leave->date_to->toDateString(),
            ]);

        return [
            'view' => 'supervisor',
            'scope' => $department->department_code,
            'data' => [
                'department' => [
                    'linked' => true,
                    'code' => $department->department_code,
                    'name' => $department->department_name,
                ],
                'metrics' => [
                    'active_personnel' => $activePersonnel,
                    'present_today' => (int) ($todaySummary->present ?? 0),
                    'late_today' => (int) ($todaySummary->late ?? 0),
                    'incomplete_today' => (int) ($todaySummary->incomplete ?? 0),
                ],
                'queues' => [
                    'attendance_verification' => $verificationCount,
                    'leave_requests' => $leaveCount,
                    'submitted_dtrs' => $submittedDtrCount,
                    'returned_dtrs' => $returnedDtrCount,
                ],
                'attendance_statuses' => $statuses,
                'pending_leave' => $pendingLeave,
                'lifecycle' => $this->lifecycleOverview($departmentId),
                'workforce_today' => $this->workforceBreakdown($departmentId),
                'upcoming_calendar' => $this->upcomingCalendar($departmentId),
            ],
        ];
    }

    private function humanResources(): array
    {
        return [
            'view' => 'hr',
            'scope' => 'All offices',
            'data' => [
                'queues' => $this->globalWorkflowQueues(),
                'queue_preview' => $this->globalQueuePreview(),
                'lifecycle' => $this->lifecycleOverview(),
                'workforce_today' => $this->workforceBreakdown(),
                'upcoming_calendar' => $this->upcomingCalendar(),
            ],
        ];
    }

    private function administrator(): array
    {
        $activePersonnel = Personnel::query()->where('status', 'Active')->count();
        $activeDepartments = Department::query()->where('status', 'Active')->count();
        $todaySummary = AttendanceRecord::query()
            ->whereDate('attendance_date', today())
            ->selectRaw('COUNT(*) as recorded')
            ->selectRaw("SUM(CASE WHEN attendance_status IN ('Present', 'Half Day') THEN 1 ELSE 0 END) as present")
            ->selectRaw('SUM(CASE WHEN late_minutes > 0 THEN 1 ELSE 0 END) as late')
            ->selectRaw("SUM(CASE WHEN attendance_status = 'Incomplete' THEN 1 ELSE 0 END) as incomplete")
            ->first();
        $unassigned = Personnel::query()
            ->where('status', 'Active')
            ->whereNull('department_id')
            ->count();
        $withoutSchedule = Personnel::query()
            ->where('status', 'Active')
            ->whereNotNull('department_id')
            ->whereDoesntHave('scheduleAssignments', function (Builder $query): void {
                $query->whereDate('effective_from', '<=', today())
                    ->where(function (Builder $dates): void {
                        $dates->whereNull('effective_to')
                            ->orWhereDate('effective_to', '>=', today());
                    })
                    ->whereHas('schedule', fn (Builder $schedule) => $schedule->where('status', 'Active'));
            })
            ->count();
        $userCounts = User::query()
            ->selectRaw("SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active")
            ->selectRaw("SUM(CASE WHEN status = 'Inactive' THEN 1 ELSE 0 END) as inactive")
            ->selectRaw("SUM(CASE WHEN status = 'Locked' OR locked_until > ? THEN 1 ELSE 0 END) as locked", [now()])
            ->selectRaw('COALESCE(SUM(failed_login_attempts), 0) as failed_attempts')
            ->first();
        $securityEvents = ActivityLog::query()
            ->where('created_at', '>=', now()->subDay())
            ->whereIn('activity_type', [
                'FAILED_LOGIN',
                'REJECTED_LOGIN',
                'ACCOUNT_TEMPORARILY_LOCKED',
            ])
            ->count();

        return [
            'view' => 'administrator',
            'scope' => 'System-wide operations',
            'data' => [
                'operations' => [
                    'active_personnel' => $activePersonnel,
                    'active_departments' => $activeDepartments,
                    'attendance_recorded_today' => (int) ($todaySummary->recorded ?? 0),
                    'present_today' => (int) ($todaySummary->present ?? 0),
                    'late_today' => (int) ($todaySummary->late ?? 0),
                    'incomplete_today' => (int) ($todaySummary->incomplete ?? 0),
                    'unassigned_personnel' => $unassigned,
                    'without_schedule' => $withoutSchedule,
                ],
                'queues' => $this->globalWorkflowQueues(),
                'security' => [
                    'active_users' => (int) ($userCounts->active ?? 0),
                    'inactive_users' => (int) ($userCounts->inactive ?? 0),
                    'locked_users' => (int) ($userCounts->locked ?? 0),
                    'failed_attempts' => (int) ($userCounts->failed_attempts ?? 0),
                    'security_events_24h' => $securityEvents,
                ],
                'queue_preview' => $this->globalQueuePreview(),
                'lifecycle' => $this->lifecycleOverview(),
                'workforce_today' => $this->workforceBreakdown(),
                'upcoming_calendar' => $this->upcomingCalendar(),
                'system_health' => $this->dashboardHealth(),
            ],
        ];
    }

    private function globalWorkflowQueues(): array
    {
        return [
            'attendance_verification' => $this->attendanceVerificationQuery()->count(),
            'correction_requests' => AttendanceCorrectionRequest::query()
                ->where('request_status', 'Pending')
                ->count(),
            'leave_requests' => $this->leaveQueueQuery()->count(),
            'submitted_dtrs' => $this->submittedDtrQueueQuery()->count(),
            'returned_dtrs' => $this->dtrQueueQuery('Returned')->count(),
        ];
    }

    private function globalQueuePreview(): array
    {
        $attendance = $this->attendanceVerificationQuery()
            ->with('personnel:personnel_id,first_name,middle_name,last_name,suffix,employee_number')
            ->oldest('attendance_date')
            ->limit(4)
            ->get()
            ->map(fn (AttendanceRecord $record): array => [
                'id' => $record->attendance_id,
                'type' => 'Attendance verification',
                'full_name' => $record->personnel?->full_name ?? 'Unknown personnel',
                'detail' => $record->attendance_date->format('M j, Y'),
                'path' => '/attendance',
            ]);
        $leave = $this->leaveQueueQuery()
            ->with('personnel:personnel_id,first_name,middle_name,last_name,suffix,employee_number')
            ->oldest('created_at')
            ->limit(4)
            ->get()
            ->map(fn (LeaveRecord $record): array => [
                'id' => $record->leave_id,
                'type' => 'Leave request',
                'full_name' => $record->personnel?->full_name ?? 'Unknown personnel',
                'detail' => $record->leave_type,
                'path' => '/leave-requests',
            ]);

        return $attendance
            ->concat($leave)
            ->take(6)
            ->values()
            ->all();
    }

    private function attendanceVerificationQuery(?int $departmentId = null): Builder
    {
        return AttendanceRecord::query()
            ->where('is_verified', false)
            ->where('attendance_date', '<=', today()->toDateString())
            ->where('attendance_status', '!=', 'Incomplete')
            ->when($departmentId, fn (Builder $query) => $query->whereHas(
                'personnel',
                fn (Builder $personnel) => $personnel->where('department_id', $departmentId)
            ));
    }

    private function leaveQueueQuery(?int $departmentId = null): Builder
    {
        return LeaveRecord::query()
            ->where('approval_status', 'Pending')
            ->when($departmentId, fn (Builder $query) => $query->whereHas(
                'personnel',
                fn (Builder $personnel) => $personnel->where('department_id', $departmentId)
            ));
    }

    private function dtrQueueQuery(string $status, ?int $departmentId = null): Builder
    {
        return DtrCertification::query()
            ->where('certification_status', $status)
            ->when($departmentId, fn (Builder $query) => $query->whereHas(
                'personnel',
                fn (Builder $personnel) => $personnel->where('department_id', $departmentId)
            ));
    }

    private function submittedDtrQueueQuery(?int $departmentId = null): Builder
    {
        return DtrCertification::query()
            ->whereIn('certification_status', ['Submitted', 'Submitted Late'])
            ->when($departmentId, fn (Builder $query) => $query->whereHas(
                'personnel',
                fn (Builder $personnel) => $personnel->where('department_id', $departmentId)
            ));
    }

    private function personalEmployment(Personnel $personnel): array
    {
        $endDate = $personnel->employment_end_date;
        $daysRemaining = $endDate ? (int) today()->diffInDays($endDate, false) : null;
        $reminderDays = max(1, min((int) config('attendance.employment_reminder_days', 30), 365));

        return [
            'status' => $personnel->status,
            'start_date' => $personnel->employment_start_date?->toDateString(),
            'end_date' => $endDate?->toDateString(),
            'days_remaining' => $daysRemaining,
            'ending_soon' => $daysRemaining !== null
                && $daysRemaining >= 0
                && $daysRemaining <= $reminderDays,
            'ended' => $daysRemaining !== null && $daysRemaining < 0,
        ];
    }

    private function lifecycleOverview(?int $departmentId = null): array
    {
        $today = today();
        $reminderDays = max(1, min((int) config('attendance.employment_reminder_days', 30), 365));
        $scope = fn (Builder $query): Builder => $query
            ->when($departmentId, fn (Builder $personnel) => $personnel->where('department_id', $departmentId));
        $active = Personnel::query()
            ->where('status', 'Active')
            ->whereNotNull('employment_end_date')
            ->tap($scope);
        $counts = (clone $active)
            ->selectRaw(
                'SUM(CASE WHEN employment_end_date BETWEEN ? AND ? THEN 1 ELSE 0 END) as expiring_7_days',
                [$today->toDateString(), $today->copy()->addDays(7)->toDateString()]
            )
            ->selectRaw(
                'SUM(CASE WHEN employment_end_date BETWEEN ? AND ? THEN 1 ELSE 0 END) as expiring_window',
                [$today->toDateString(), $today->copy()->addDays($reminderDays)->toDateString()]
            )
            ->selectRaw(
                'SUM(CASE WHEN employment_end_date < ? THEN 1 ELSE 0 END) as awaiting_offboarding',
                [$today->toDateString()]
            )
            ->first();
        $recentCompleted = Personnel::query()
            ->where('status', 'Completed')
            ->where('updated_at', '>=', now()->subDays(30))
            ->tap($scope)
            ->count();
        $upcoming = (clone $active)
            ->with('department:department_id,department_code')
            ->whereBetween('employment_end_date', [
                $today->toDateString(),
                $today->copy()->addDays($reminderDays)->toDateString(),
            ])
            ->orderBy('employment_end_date')
            ->orderBy('personnel_id')
            ->limit(6)
            ->get([
                'personnel_id',
                'department_id',
                'employee_number',
                'first_name',
                'middle_name',
                'last_name',
                'suffix',
                'employment_end_date',
            ])
            ->map(fn (Personnel $personnel): array => [
                'personnel_id' => $personnel->personnel_id,
                'full_name' => $personnel->full_name,
                'employee_number' => $personnel->employee_number,
                'department' => $personnel->department?->department_code ?? 'Unassigned',
                'end_date' => $personnel->employment_end_date->toDateString(),
                'days_remaining' => (int) $today->diffInDays($personnel->employment_end_date, false),
            ]);

        return [
            'reminder_window_days' => $reminderDays,
            'expiring_7_days' => (int) ($counts->expiring_7_days ?? 0),
            'expiring_30_days' => (int) ($counts->expiring_window ?? 0),
            'awaiting_offboarding' => (int) ($counts->awaiting_offboarding ?? 0),
            'completed_30_days' => $recentCompleted,
            'upcoming' => $upcoming,
        ];
    }

    private function emptyLifecycleOverview(): array
    {
        return [
            'reminder_window_days' => max(1, min((int) config('attendance.employment_reminder_days', 30), 365)),
            'expiring_7_days' => 0,
            'expiring_30_days' => 0,
            'awaiting_offboarding' => 0,
            'completed_30_days' => 0,
            'upcoming' => [],
        ];
    }

    private function dtrReadiness(
        Personnel $personnel,
        array $context,
        ?DtrCertification $certification
    ): array {
        $cutoff = today()->lessThan($context['end']) ? today() : $context['end']->copy();
        $assignments = PersonnelSchedule::query()
            ->with('schedule')
            ->where('personnel_id', $personnel->personnel_id)
            ->whereDate('effective_from', '<=', $cutoff)
            ->where(function (Builder $query) use ($context): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $context['start']);
            })
            ->orderByDesc('effective_from')
            ->get();
        $records = AttendanceRecord::query()
            ->where('personnel_id', $personnel->personnel_id)
            ->whereBetween('attendance_date', [
                $context['start']->toDateString(),
                $cutoff->toDateString(),
            ])
            ->get()
            ->keyBy(fn (AttendanceRecord $record): string => $record->attendance_date->toDateString());
        $holidays = Holiday::query()
            ->whereBetween('holiday_date', [
                $context['start']->toDateString(),
                $cutoff->toDateString(),
            ])
            ->where(function (Builder $query) use ($personnel): void {
                $query->whereNull('department_id')
                    ->orWhere('department_id', $personnel->department_id);
            })
            ->get()
            ->groupBy(fn (Holiday $holiday): string => $holiday->holiday_date->toDateString());
        $expected = 0;
        $missing = 0;
        $incomplete = 0;
        $unverified = 0;
        $scheduleGaps = 0;

        foreach (CarbonPeriod::create($context['start'], $cutoff) as $date) {
            if ($personnel->employment_start_date && $date->lessThan($personnel->employment_start_date)) {
                continue;
            }
            if ($personnel->employment_end_date && $date->greaterThan($personnel->employment_end_date)) {
                continue;
            }

            $assignment = $assignments->first(
                fn (PersonnelSchedule $item): bool => $item->effective_from->lte($date)
                    && (! $item->effective_to || $item->effective_to->gte($date))
            );
            $schedule = $assignment?->schedule;
            if (! $schedule) {
                $scheduleGaps++;

                continue;
            }

            $events = $holidays->get($date->toDateString(), collect());
            $holiday = $events->first(
                fn (Holiday $event): bool => $event->holiday_type !== 'Special Working Holiday'
            );
            $specialWorkingDay = $events->contains(
                fn (Holiday $event): bool => $event->holiday_type === 'Special Working Holiday'
            );
            $isDutyDay = ! $holiday
                && ($schedule->{strtolower($date->format('l'))} || $specialWorkingDay);
            if (! $isDutyDay) {
                continue;
            }

            $expected++;
            $record = $records->get($date->toDateString());
            if (! $record) {
                $missing++;

                continue;
            }
            if ($record->attendance_status === 'Incomplete') {
                $incomplete++;
            }
            if (! $record->is_verified) {
                $unverified++;
            }
        }

        $resolved = max(0, $expected - $missing - $incomplete);
        $timeline = $this->cutoffs->timeline($context, $certification?->certification_status);

        return [
            'status' => $certification?->certification_status ?? 'Not started',
            'period' => $certification?->dtr_period ?? $context['period'],
            'period_label' => $context['label'],
            'version' => $certification?->version_number,
            'updated_at' => $certification?->updated_at?->toISOString(),
            'cutoff' => $timeline,
            'expected_days' => $expected,
            'completion_percent' => $expected > 0
                ? (int) round(($resolved / $expected) * 100)
                : null,
            'issues' => [
                'missing' => $missing,
                'incomplete' => $incomplete,
                'unverified' => $unverified,
                'schedule_gaps' => $scheduleGaps,
            ],
            'is_ready' => $scheduleGaps === 0
                && $expected > 0
                && $missing === 0
                && $incomplete === 0
                && $unverified === 0,
        ];
    }

    private function workforceBreakdown(?int $departmentId = null): array
    {
        $date = today();
        $events = Holiday::query()
            ->whereDate('holiday_date', $date)
            ->get();
        $nonWorking = $events->where('holiday_type', '!=', 'Special Working Holiday');
        $globalHoliday = $nonWorking->contains(fn (Holiday $event): bool => ! $event->department_id);
        $nonWorkingDepartments = $nonWorking->pluck('department_id')->filter()->map(
            fn ($id): int => (int) $id
        )->values()->all();
        if ($globalHoliday || ($departmentId && in_array($departmentId, $nonWorkingDepartments, true))) {
            return $this->emptyWorkforceBreakdown();
        }

        $specialWorking = $events->where('holiday_type', 'Special Working Holiday');
        $globalSpecialWorking = $specialWorking->contains(fn (Holiday $event): bool => ! $event->department_id);
        $specialWorkingDepartments = $specialWorking->pluck('department_id')->filter()->map(
            fn ($id): int => (int) $id
        )->values()->all();
        $dayField = strtolower($date->format('l'));
        $activeAssignment = function (Builder $query) use ($date): void {
            $query->whereDate('effective_from', '<=', $date)
                ->where(function (Builder $dates) use ($date): void {
                    $dates->whereNull('effective_to')
                        ->orWhereDate('effective_to', '>=', $date);
                })
                ->whereHas('schedule', fn (Builder $schedule) => $schedule->where('status', 'Active'));
        };
        $regularAssignment = function (Builder $query) use ($activeAssignment, $dayField): void {
            $activeAssignment($query);
            $query->whereHas(
                'schedule',
                fn (Builder $schedule) => $schedule->where($dayField, true)
            );
        };
        $expectedQuery = Personnel::query()
            ->where('status', 'Active')
            ->when($departmentId, fn (Builder $query) => $query->where('department_id', $departmentId))
            ->when(
                $nonWorkingDepartments !== [],
                fn (Builder $query) => $query->whereNotIn('department_id', $nonWorkingDepartments)
            )
            ->where(function (Builder $query) use ($date): void {
                $query->whereNull('employment_start_date')
                    ->orWhereDate('employment_start_date', '<=', $date);
            })
            ->where(function (Builder $query) use ($date): void {
                $query->whereNull('employment_end_date')
                    ->orWhereDate('employment_end_date', '>=', $date);
            })
            ->whereHas('scheduleAssignments', $activeAssignment);

        if (! $globalSpecialWorking) {
            $expectedQuery->where(function (Builder $query) use (
                $regularAssignment,
                $specialWorkingDepartments
            ): void {
                $query->whereHas('scheduleAssignments', $regularAssignment);
                if ($specialWorkingDepartments !== []) {
                    $query->orWhereIn('department_id', $specialWorkingDepartments);
                }
            });
        }

        $expectedIds = (clone $expectedQuery)->select('personnel_id');
        $attendanceBase = AttendanceRecord::query()
            ->whereDate('attendance_date', $date)
            ->whereIn('personnel_id', clone $expectedIds);
        $attendance = (clone $attendanceBase)
            ->selectRaw('attendance_status, COUNT(DISTINCT personnel_id) as total')
            ->groupBy('attendance_status')
            ->pluck('total', 'attendance_status');
        $covered = (clone $expectedQuery)
            ->where(function (Builder $query) use ($date): void {
                $query->whereHas(
                    'attendanceRecords',
                    fn (Builder $attendance) => $attendance->whereDate('attendance_date', $date)
                )->orWhereHas('leaveRecords', function (Builder $leave) use ($date): void {
                    $leave->where('approval_status', 'Approved')
                        ->whereDate('date_from', '<=', $date)
                        ->whereDate('date_to', '>=', $date);
                });
            })
            ->count();
        $leaveCount = $this->coveredLeaveCount($expectedQuery, false);
        $officialBusinessCount = $this->coveredLeaveCount($expectedQuery, true);
        $expected = (clone $expectedQuery)->count();

        return [
            'expected' => $expected,
            'recorded' => (clone $attendanceBase)->distinct()->count('personnel_id'),
            'present' => (int) (($attendance['Present'] ?? 0) + ($attendance['Half Day'] ?? 0)),
            'absent' => (int) ($attendance['Absent'] ?? 0),
            'leave' => max((int) ($attendance['Leave'] ?? 0), $leaveCount),
            'official_business' => max(
                (int) ($attendance['Official Business'] ?? 0),
                $officialBusinessCount
            ),
            'incomplete' => (int) ($attendance['Incomplete'] ?? 0),
            'not_started' => max(0, $expected - $covered),
        ];
    }

    private function coveredLeaveCount(Builder $expectedQuery, bool $officialBusiness): int
    {
        return (clone $expectedQuery)
            ->whereHas('leaveRecords', function (Builder $query) use ($officialBusiness): void {
                $query->where('approval_status', 'Approved')
                    ->whereDate('date_from', '<=', today())
                    ->whereDate('date_to', '>=', today())
                    ->when(
                        $officialBusiness,
                        fn (Builder $leave) => $leave->where('leave_type', 'Official Business'),
                        fn (Builder $leave) => $leave->where('leave_type', '!=', 'Official Business')
                    );
            })
            ->count();
    }

    private function emptyWorkforceBreakdown(): array
    {
        return [
            'expected' => 0,
            'recorded' => 0,
            'present' => 0,
            'absent' => 0,
            'leave' => 0,
            'official_business' => 0,
            'incomplete' => 0,
            'not_started' => 0,
        ];
    }

    private function upcomingCalendar(?int $departmentId = null, ?int $personnelId = null): array
    {
        $start = today();
        $end = today()->addDays(14);
        $holidays = Holiday::query()
            ->select([
                'holiday_id',
                'department_id',
                'holiday_date',
                'holiday_name',
                'holiday_type',
                'scope',
            ])
            ->with('department:department_id,department_code')
            ->whereBetween('holiday_date', [$start, $end])
            ->when($departmentId, fn (Builder $query) => $query->where(function (Builder $scope) use ($departmentId): void {
                $scope->whereNull('department_id')->orWhere('department_id', $departmentId);
            }))
            ->orderBy('holiday_date')
            ->limit(12)
            ->get()
            ->map(fn (Holiday $holiday): array => [
                'id' => 'holiday-'.$holiday->holiday_id,
                'type' => $holiday->holiday_type === 'Special Working Holiday' ? 'duty_day' : 'holiday',
                'date' => $holiday->holiday_date->toDateString(),
                'title' => $holiday->holiday_name,
                'detail' => $holiday->department?->department_code ?? $holiday->scope,
            ]);

        $personalEvents = collect();
        if ($personnelId) {
            $leaves = LeaveRecord::query()
                ->where('personnel_id', $personnelId)
                ->where('approval_status', 'Approved')
                ->whereDate('date_from', '<=', $end)
                ->whereDate('date_to', '>=', $start)
                ->orderBy('date_from')
                ->limit(6)
                ->get(['leave_id', 'leave_type', 'date_from', 'date_to'])
                ->map(function (LeaveRecord $leave) use ($start): array {
                    $displayDate = $leave->date_from->lessThan($start) ? $start : $leave->date_from;

                    return [
                        'id' => 'leave-'.$leave->leave_id,
                        'type' => $leave->leave_type === 'Official Business' ? 'official_business' : 'leave',
                        'date' => $displayDate->toDateString(),
                        'title' => $leave->leave_type,
                        'detail' => $leave->date_from->equalTo($leave->date_to)
                            ? 'Approved for this date'
                            : 'Approved through '.$leave->date_to->format('M j'),
                    ];
                });
            $scheduleChanges = PersonnelSchedule::query()
                ->with('schedule:schedule_id,schedule_name')
                ->where('personnel_id', $personnelId)
                ->whereBetween('effective_from', [$start->copy()->addDay(), $end])
                ->orderBy('effective_from')
                ->limit(4)
                ->get()
                ->map(fn (PersonnelSchedule $assignment): array => [
                    'id' => 'schedule-'.$assignment->personnel_schedule_id,
                    'type' => 'schedule',
                    'date' => $assignment->effective_from->toDateString(),
                    'title' => 'Schedule assignment starts',
                    'detail' => $assignment->schedule?->schedule_name ?? 'Assigned work schedule',
                ]);
            $personalEvents = $leaves->concat($scheduleChanges);
        }

        return $holidays
            ->concat($personalEvents)
            ->sortBy(fn (array $event): string => $event['date'].'-'.$event['id'])
            ->take(8)
            ->values()
            ->all();
    }

    private function dashboardHealth(): array
    {
        $health = $this->systemHealth->snapshot();

        return [
            'overall_status' => $health['overall_status'],
            'checked_at' => $health['checked_at'],
            'release' => $health['release'],
            'summary' => $health['summary'],
            'checks' => collect($health['checks'])
                ->whereIn('key', ['api', 'database', 'scheduler', 'backup'])
                ->map(fn (array $check): array => [
                    'key' => $check['key'],
                    'label' => $check['label'],
                    'status' => $check['status'],
                    'message' => $check['message'],
                    'last_success_at' => $check['last_success_at'],
                ])
                ->values()
                ->all(),
        ];
    }

    private function formatTodayRecord(
        ?AttendanceRecord $record,
        ?PersonnelSchedule $assignment,
        Personnel $personnel
    ): array {
        $action = $this->attendanceAction($record, $assignment, $personnel);

        if (! $record) {
            return [
                'recorded' => false,
                'status' => 'Not started',
                ...$action,
            ];
        }

        return [
            'recorded' => true,
            'status' => $record->attendance_status,
            'morning_in' => $record->morning_time_in?->format('h:i A'),
            'morning_out' => $record->morning_time_out?->format('h:i A'),
            'afternoon_in' => $record->afternoon_time_in?->format('h:i A'),
            'afternoon_out' => $record->afternoon_time_out?->format('h:i A'),
            'work_minutes' => $record->total_work_minutes,
            'verified' => $record->is_verified,
            ...$action,
        ];
    }

    private function attendanceAction(
        ?AttendanceRecord $record,
        ?PersonnelSchedule $assignment,
        Personnel $personnel
    ): array {
        $schedule = $assignment?->schedule;
        $now = now(config('app.timezone'));

        if (! $schedule) {
            return $this->actionResult(null, 'No active work schedule is assigned. Contact HR or your administrator.');
        }

        $events = Holiday::query()
            ->whereDate('holiday_date', $now->toDateString())
            ->where(function (Builder $query) use ($personnel): void {
                $query->whereNull('department_id')
                    ->orWhere('department_id', $personnel->department_id);
            })
            ->get();
        $holiday = $events->first(
            fn (Holiday $event): bool => $event->holiday_type !== 'Special Working Holiday'
        );
        if ($holiday) {
            return $this->actionResult(null, 'Attendance is closed for '.$holiday->holiday_name.'.');
        }

        $specialWorkingDay = $events->contains(
            fn (Holiday $event): bool => $event->holiday_type === 'Special Working Holiday'
        );
        $dayField = strtolower($now->format('l'));
        if (! $schedule->{$dayField} && ! $specialWorkingDay) {
            return $this->actionResult(null, 'Today is not a duty day in your assigned schedule.');
        }

        if ($record && in_array($record->attendance_status, [
            'Absent',
            'Leave',
            'Holiday',
            'Rest Day',
            'Official Business',
            'Work From Home',
            'Half Day',
        ], true)) {
            return $this->actionResult(
                null,
                'Today is marked as '.$record->attendance_status.' and does not accept another time entry.'
            );
        }

        if ($record?->is_complete) {
            return $this->actionResult(null, 'Today\'s required time entries are complete.');
        }

        $windows = [
            [
                'action' => 'morning_time_in',
                'label' => 'Morning Time In',
                'start' => $schedule->morning_time_in_start ?? $schedule->morning_start,
                'end' => $schedule->morning_time_out_end ?? $schedule->morning_end,
                'requires' => null,
            ],
            [
                'action' => 'morning_time_out',
                'label' => 'Morning Time Out',
                'start' => $schedule->morning_time_out_start ?? $schedule->morning_end,
                'end' => $schedule->morning_time_out_end ?? $schedule->morning_end,
                'requires' => 'morning_time_in',
            ],
            [
                'action' => 'afternoon_time_in',
                'label' => 'Afternoon Time In',
                'start' => $schedule->afternoon_time_in_start ?? $schedule->afternoon_start,
                'end' => $schedule->afternoon_time_in_end ?? $schedule->afternoon_start,
                'requires' => null,
            ],
            [
                'action' => 'afternoon_time_out',
                'label' => 'Afternoon Time Out',
                'start' => $schedule->afternoon_time_out_start ?? $schedule->afternoon_end,
                'end' => $schedule->afternoon_time_out_end ?? $schedule->afternoon_end,
                'requires' => 'afternoon_time_in',
            ],
        ];

        foreach ($windows as $window) {
            if (! $window['start'] || ! $window['end'] || $record?->{$window['action']}) {
                continue;
            }

            $start = Carbon::parse($now->toDateString().' '.$window['start'], $now->getTimezone());
            $end = Carbon::parse($now->toDateString().' '.$window['end'], $now->getTimezone());
            if (! $now->betweenIncluded($start, $end)) {
                continue;
            }

            if ($window['requires'] && ! $record?->{$window['requires']}) {
                return $this->actionResult(
                    null,
                    $window['label'].' requires the matching time-in entry.'
                );
            }

            return $this->actionResult(
                $window['action'],
                $window['label'].' is available now.'
            );
        }

        foreach ($windows as $window) {
            if (! $window['start'] || $record?->{$window['action']}) {
                continue;
            }

            $start = Carbon::parse($now->toDateString().' '.$window['start'], $now->getTimezone());
            if ($start->greaterThan($now)) {
                return $this->actionResult(
                    null,
                    $window['label'].' opens at '.$start->format('h:i A').'.'
                );
            }
        }

        return $this->actionResult(null, 'All attendance windows have closed for today.');
    }

    private function actionResult(?string $action, string $message): array
    {
        return [
            'next_action' => $action,
            'next_action_label' => $action
                ? str($action)->replace('_', ' ')->title()->toString()
                : null,
            'action_available' => $action !== null,
            'action_message' => $message,
        ];
    }

    private function formatSchedule(?PersonnelSchedule $assignment): array
    {
        $schedule = $assignment?->schedule;

        if (! $schedule) {
            return ['assigned' => false];
        }

        $workingDays = collect([
            'Mon' => $schedule->monday,
            'Tue' => $schedule->tuesday,
            'Wed' => $schedule->wednesday,
            'Thu' => $schedule->thursday,
            'Fri' => $schedule->friday,
            'Sat' => $schedule->saturday,
            'Sun' => $schedule->sunday,
        ])->filter()->keys()->values();

        return [
            'assigned' => true,
            'name' => $schedule->schedule_name,
            'working_days' => $workingDays,
            'morning' => $this->timeRange($schedule->morning_start, $schedule->morning_end),
            'afternoon' => $this->timeRange($schedule->afternoon_start, $schedule->afternoon_end),
            'required_minutes' => $schedule->required_minutes_per_day,
            'effective_from' => $assignment->effective_from->toDateString(),
            'effective_to' => $assignment->effective_to?->toDateString(),
        ];
    }

    private function timeRange(?string $start, ?string $end): ?string
    {
        if (! $start || ! $end) {
            return null;
        }

        return Carbon::createFromFormat('H:i:s', $start)->format('g:i A')
            .' – '
            .Carbon::createFromFormat('H:i:s', $end)->format('g:i A');
    }

    private function emptyPersonalData(): array
    {
        return [
            'profile' => ['linked' => false],
            'employment' => [
                'status' => 'Unavailable',
                'start_date' => null,
                'end_date' => null,
                'days_remaining' => null,
                'ending_soon' => false,
                'ended' => false,
            ],
            'metrics' => [
                'work_minutes' => 0,
                'days_present' => 0,
                'late_minutes' => 0,
                'undertime_minutes' => 0,
            ],
            'today_attendance' => [
                'recorded' => false,
                'status' => 'Unavailable',
                'next_action' => null,
                'next_action_label' => null,
                'action_available' => false,
                'action_message' => 'Link this account to a personnel record to use attendance.',
            ],
            'schedule' => ['assigned' => false],
            'leave' => ['pending' => 0, 'approved' => 0, 'recent' => []],
            'dtr' => [
                'status' => 'Not started',
                'period' => null,
                'period_label' => 'Unavailable',
                'version' => null,
                'updated_at' => null,
                'cutoff' => null,
                'expected_days' => 0,
                'completion_percent' => null,
                'issues' => [
                    'missing' => 0,
                    'incomplete' => 0,
                    'unverified' => 0,
                    'schedule_gaps' => 0,
                ],
                'is_ready' => false,
            ],
            'upcoming_calendar' => [],
            'recent_attendance' => [],
        ];
    }

    private function emptyOperationsMetrics(): array
    {
        return [
            'active_personnel' => 0,
            'present_today' => 0,
            'late_today' => 0,
            'incomplete_today' => 0,
        ];
    }

    private function emptySupervisorQueues(): array
    {
        return [
            'attendance_verification' => 0,
            'leave_requests' => 0,
            'submitted_dtrs' => 0,
            'returned_dtrs' => 0,
        ];
    }
}
