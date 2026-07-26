<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceChangeLog;
use App\Models\AttendanceRecord;
use App\Models\DtrCertification;
use App\Models\Holiday;
use App\Models\Personnel;
use App\Models\PersonnelSchedule;
use App\Models\TimeLog;
use App\Models\WorkSchedule;
use App\Support\PersonnelAccess;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AttendanceController extends Controller
{
    private const STATUS_FILTERS = ['Present', 'Half Day', 'Late', 'Incomplete', 'Not Started', 'Leave', 'Holiday'];

    private const ACTIONS = [
        'morning_time_in' => 'Morning In',
        'morning_time_out' => 'Morning Out',
        'afternoon_time_in' => 'Afternoon In',
        'afternoon_time_out' => 'Afternoon Out',
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(self::STATUS_FILTERS)],
        ]);
        $date = Carbon::parse($validated['date'] ?? now()->toDateString())->toDateString();
        $user = $request->user();
        $visiblePersonnelIds = PersonnelAccess::scope(
            Personnel::query()->where('status', 'Active'),
            $user
        )->pluck('personnel_id');
        $holidays = Holiday::query()
            ->whereDate('holiday_date', $date)
            ->orderByRaw('department_id IS NULL DESC')
            ->get();
        $holiday = $holidays->first();
        $isToday = $date === now()->toDateString();
        $scheduleAssignments = PersonnelSchedule::query()
            ->with('schedule')
            ->whereIn('personnel_id', $visiblePersonnelIds)
            ->whereDate('effective_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->get()
            ->unique('personnel_id')
            ->keyBy('personnel_id');

        $personnel = Personnel::query()
            ->with([
                'department:department_id,department_code,department_name',
                'attendanceRecords' => fn ($query) => $query
                    ->whereDate('attendance_date', $date)
                    ->with('schedule'),
            ])
            ->where('status', 'Active')
            ->whereIn('personnel_id', $visiblePersonnelIds)
            ->when($validated['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('employee_number', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('middle_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%");
                });
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(function (Personnel $person) use ($holidays, $scheduleAssignments, $isToday, $date): array {
                $record = $person->attendanceRecords->first();
                $schedule = $record?->schedule
                    ?? $scheduleAssignments->get($person->personnel_id)?->schedule;
                $referenceTime = $isToday ? now() : Carbon::parse($date)->endOfDay();
                $appliesToPersonnel = fn (Holiday $event): bool => ! $event->department_id || $event->department_id === $person->department_id;
                $personHoliday = $holidays->first(fn (Holiday $event): bool => $event->holiday_type !== 'Special Working Holiday' && $appliesToPersonnel($event)
                );
                $isSpecialWorkingDay = $holidays->contains(fn (Holiday $event): bool => $event->holiday_type === 'Special Working Holiday' && $appliesToPersonnel($event)
                );

                if ($record) {
                    $this->syncCalculatedStatus($record, $schedule, $referenceTime);
                }

                return $this->formatDailyPersonnel(
                    $person,
                    $record,
                    $personHoliday,
                    $schedule,
                    $referenceTime,
                    $isToday,
                    $isSpecialWorkingDay
                );
            });

        if ($validated['status'] ?? null) {
            $status = $validated['status'];
            $personnel = $personnel
                ->filter(fn (array $row) => $status === 'Late'
                    ? $row['is_late']
                    : $row['display_status'] === $status)
                ->values();
        }

        $recentLogs = TimeLog::query()
            ->with('personnel:personnel_id,first_name,middle_name,last_name,suffix,employee_number')
            ->whereIn('personnel_id', $visiblePersonnelIds)
            ->whereDate('log_datetime', $date)
            ->orderByDesc('log_datetime')
            ->limit(12)
            ->get()
            ->map(fn (TimeLog $log) => [
                'time_log_id' => $log->time_log_id,
                'personnel_id' => $log->personnel_id,
                'full_name' => $log->personnel?->full_name,
                'employee_number' => $log->personnel?->employee_number,
                'log_type' => $log->log_type,
                'log_datetime' => $log->log_datetime->toISOString(),
                'time' => $log->log_datetime->format('h:i A'),
                'source' => $log->log_source,
            ]);

        $allRows = collect($personnel);

        return response()->json([
            'date' => $date,
            'is_today' => $date === now()->toDateString(),
            'holiday' => $holiday ? [
                'holiday_name' => $holiday->holiday_name,
                'holiday_type' => $holiday->holiday_type,
            ] : null,
            'data' => $personnel,
            'recent_logs' => $recentLogs,
            'summary' => [
                'total' => $allRows->count(),
                'timed_in' => $allRows->filter(
                    fn (array $row) => $row['morning_time_in'] || $row['afternoon_time_in']
                )->count(),
                'completed' => $allRows->where('display_status', 'Present')->count(),
                'half_day' => $allRows->where('display_status', 'Half Day')->count(),
                'late' => $allRows->where('is_late', true)->count(),
                'incomplete' => $allRows->where('display_status', 'Incomplete')->count(),
                'not_started' => $allRows->where('display_status', 'Not Started')->count(),
            ],
        ]);
    }

    public function options(Request $request): JsonResponse
    {
        $user = $request->user();
        $canManageOthers = PersonnelAccess::canManageOthers($user);

        return response()->json([
            'server_time' => now()->toISOString(),
            'timezone' => config('app.timezone'),
            'current_user_personnel_id' => $user->personnel_id,
            'can_manage_others' => $canManageOthers,
            'personnel' => Personnel::query()
                ->where('status', 'Active')
                ->tap(fn ($query) => PersonnelAccess::scope($query, $user))
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get(['personnel_id', 'employee_number', 'first_name', 'middle_name', 'last_name', 'suffix'])
                ->map(fn (Personnel $person) => [
                    'personnel_id' => $person->personnel_id,
                    'employee_number' => $person->employee_number,
                    'full_name' => $person->full_name,
                ]),
            'status_filters' => self::STATUS_FILTERS,
        ]);
    }

    public function recordTime(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'personnel_id' => ['required', 'integer', 'exists:personnel,personnel_id'],
            'device_identifier' => ['nullable', 'string', 'max:255'],
        ]);
        $user = $request->user();
        $trustedQrScan = $request->attributes->get('trusted_qr_scan') === true;

        if (! $trustedQrScan && (int) $user->personnel_id !== (int) $validated['personnel_id']) {
            return response()->json([
                'message' => 'You may only record attendance for your own personnel account.',
            ], 403);
        }

        if (! $trustedQrScan && ! $user->personnel_id) {
            return response()->json([
                'message' => 'Your account is not linked to a personnel record.',
            ], 422);
        }

        $personnel = Personnel::query()
            ->where('status', 'Active')
            ->findOrFail($validated['personnel_id']);
        $now = now();
        $date = $now->toDateString();
        $schedule = $this->effectiveSchedule($personnel->personnel_id, $date);
        $calendarEvents = Holiday::query()
            ->whereDate('holiday_date', $date)
            ->where(fn ($query) => $query
                ->whereNull('department_id')
                ->orWhere('department_id', $personnel->department_id))
            ->get();
        $nonWorkingHoliday = $calendarEvents->firstWhere('holiday_type', '!=', 'Special Working Holiday');

        if ($nonWorkingHoliday) {
            return response()->json([
                'message' => 'Attendance is closed for '.$nonWorkingHoliday->holiday_name.'.',
            ], 422);
        }

        $isSpecialWorkingDay = $calendarEvents->contains(
            'holiday_type',
            'Special Working Holiday'
        );

        $result = DB::transaction(function () use ($personnel, $schedule, $now, $request, $validated, $user, $isSpecialWorkingDay): array {
            $record = AttendanceRecord::query()
                ->where('personnel_id', $personnel->personnel_id)
                ->whereDate('attendance_date', $now->toDateString())
                ->lockForUpdate()
                ->first();

            if ($record && in_array($record->attendance_status, [
                'Absent',
                'Leave',
                'Holiday',
                'Rest Day',
                'Official Business',
                'Work From Home',
                'Half Day',
            ], true)) {
                return [
                    'error' => 'This record is marked as '.$record->attendance_status.' and cannot accept a time log.',
                    'record' => $record,
                ];
            }

            $eligibility = $this->determineAvailableAction($record, $schedule, $now, $isSpecialWorkingDay);
            $action = $eligibility['action'];

            if (! $action || ! isset(self::ACTIONS[$action])) {
                return [
                    'error' => $eligibility['message'],
                    'record' => $record,
                ];
            }

            if (! $record) {
                $record = AttendanceRecord::create([
                    'personnel_id' => $personnel->personnel_id,
                    'schedule_id' => $schedule?->schedule_id,
                    'attendance_date' => $now->toDateString(),
                    'attendance_status' => 'Incomplete',
                    'record_source' => 'Web Portal',
                    'created_by' => $user->user_id,
                ]);
            }

            $record->{$action} = $now;
            $this->recalculate($record, $schedule, $now);
            $record->save();

            TimeLog::create([
                'personnel_id' => $personnel->personnel_id,
                'attendance_id' => $record->attendance_id,
                'log_datetime' => $now,
                'log_type' => self::ACTIONS[$action],
                'log_source' => 'Web Portal',
                'ip_address' => $request->ip(),
                'device_identifier' => $validated['device_identifier'] ?? $request->userAgent(),
                'created_by' => $user->user_id,
            ]);

            return [
                'action' => self::ACTIONS[$action],
                'record' => $record->fresh(['schedule']),
            ];
        });

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], 422);
        }

        return response()->json([
            'message' => $result['action'].' recorded at '.$now->format('h:i:s A').'.',
            'action' => $result['action'],
            'data' => $this->formatAttendance($result['record'], $schedule, now(), true, $isSpecialWorkingDay),
        ]);
    }

    public function verify(Request $request, AttendanceRecord $attendance): JsonResponse
    {
        if (! in_array($request->user()->user_role, ['Administrator', 'HR', 'Supervisor'], true)) {
            return response()->json([
                'message' => 'You do not have permission to verify attendance records.',
            ], 403);
        }

        $attendance->loadMissing('personnel');

        if (! $attendance->personnel || ! PersonnelAccess::canAccess($request->user(), $attendance->personnel)) {
            return response()->json([
                'message' => 'This attendance record is outside your assigned office scope.',
            ], 403);
        }

        $certificationStatus = DtrCertification::query()
            ->where('personnel_id', $attendance->personnel_id)
            ->where('dtr_year', $attendance->attendance_date->year)
            ->where('dtr_month', $attendance->attendance_date->month)
            ->value('certification_status');

        if (in_array($certificationStatus, ['Submitted', 'Certified'], true)) {
            return response()->json([
                'message' => $certificationStatus === 'Certified'
                    ? 'This DTR is certified and locked.'
                    : 'Return the submitted DTR before changing attendance verification.',
            ], 422);
        }

        DB::transaction(function () use ($attendance, $request): void {
            $oldValues = [
                'is_verified' => (bool) $attendance->is_verified,
                'verified_by' => $attendance->verified_by,
                'verified_at' => $attendance->verified_at?->toISOString(),
            ];
            $attendance->forceFill([
                'is_verified' => true,
                'verified_by' => $request->user()->user_id,
                'verified_at' => now(),
            ])->save();

            AttendanceChangeLog::create([
                'attendance_id' => $attendance->attendance_id,
                'changed_by' => $request->user()->user_id,
                'action_type' => 'Verified',
                'old_values' => $oldValues,
                'new_values' => [
                    'is_verified' => true,
                    'verified_by' => $request->user()->user_id,
                    'verified_at' => $attendance->verified_at?->toISOString(),
                ],
                'reason' => 'Attendance verification.',
            ]);
        });

        return response()->json([
            'message' => 'Attendance record verified successfully.',
            'data' => $this->formatAttendance(
                $attendance->fresh(['schedule']),
                $attendance->schedule,
                now(),
                $attendance->attendance_date->isToday()
            ),
        ]);
    }

    public function verifyBulk(Request $request): JsonResponse
    {
        if (! in_array($request->user()->user_role, ['Administrator', 'HR', 'Supervisor'], true)) {
            return response()->json([
                'message' => 'You do not have permission to verify attendance records.',
            ], 403);
        }

        $validated = $request->validate([
            'attendance_ids' => ['required', 'array', 'min:1', 'max:31'],
            'attendance_ids.*' => ['integer', 'distinct', 'exists:attendance_records,attendance_id'],
        ]);
        $records = AttendanceRecord::query()
            ->with(['schedule', 'personnel'])
            ->whereIn('attendance_id', $validated['attendance_ids'])
            ->get();

        foreach ($records as $record) {
            if (! $record->personnel || ! PersonnelAccess::canAccess($request->user(), $record->personnel)) {
                return response()->json([
                    'message' => 'One or more attendance records are outside your assigned office scope.',
                ], 403);
            }

            $certificationStatus = DtrCertification::query()
                ->where('personnel_id', $record->personnel_id)
                ->where('dtr_year', $record->attendance_date->year)
                ->where('dtr_month', $record->attendance_date->month)
                ->value('certification_status');

            if (in_array($certificationStatus, ['Submitted', 'Certified'], true)) {
                return response()->json([
                    'message' => 'Return submitted DTRs before verifying attendance, and never modify certified DTRs.',
                ], 422);
            }

            if ($this->determineAttendanceStatus(
                $record,
                $record->schedule,
                $record->attendance_date->copy()->endOfDay()
            ) === 'Incomplete') {
                return response()->json([
                    'message' => 'Incomplete entries must be corrected before bulk verification.',
                ], 422);
            }
        }

        DB::transaction(function () use ($records, $request): void {
            foreach ($records as $record) {
                $oldValues = [
                    'is_verified' => (bool) $record->is_verified,
                    'verified_by' => $record->verified_by,
                    'verified_at' => $record->verified_at?->toISOString(),
                ];
                $record->forceFill([
                    'is_verified' => true,
                    'verified_by' => $request->user()->user_id,
                    'verified_at' => now(),
                ])->save();

                AttendanceChangeLog::create([
                    'attendance_id' => $record->attendance_id,
                    'changed_by' => $request->user()->user_id,
                    'action_type' => 'Verified',
                    'old_values' => $oldValues,
                    'new_values' => [
                        'is_verified' => true,
                        'verified_by' => $request->user()->user_id,
                        'verified_at' => $record->verified_at?->toISOString(),
                    ],
                    'reason' => 'Bulk verification from DTR exception review.',
                ]);
            }
        });

        return response()->json([
            'message' => $records->count().' attendance '
                .str('record')->plural($records->count())
                .' verified successfully.',
            'verified_count' => $records->count(),
        ]);
    }

    public function correct(Request $request): JsonResponse
    {
        if (! in_array($request->user()->user_role, ['Administrator', 'HR'], true)) {
            return response()->json([
                'message' => 'Only an administrator or HR user may correct historical attendance.',
            ], 403);
        }

        $validated = $request->validate([
            'personnel_id' => ['required', 'integer', 'exists:personnel,personnel_id'],
            'attendance_date' => ['required', 'date', 'before_or_equal:today'],
            'record_type' => ['required', Rule::in([
                'Time Entries',
                'Absent',
                'Leave',
                'Official Business',
                'Work From Home',
            ])],
            'morning_time_in' => ['nullable', 'date_format:H:i'],
            'morning_time_out' => ['nullable', 'date_format:H:i'],
            'afternoon_time_in' => ['nullable', 'date_format:H:i'],
            'afternoon_time_out' => ['nullable', 'date_format:H:i'],
            'reason' => ['required', 'string', 'min:10', 'max:255'],
        ]);
        $date = Carbon::parse($validated['attendance_date'])->toDateString();
        $personnel = Personnel::query()->where('status', 'Active')->findOrFail($validated['personnel_id']);
        $certification = DtrCertification::query()
            ->where('personnel_id', $personnel->personnel_id)
            ->where('dtr_year', Carbon::parse($date)->year)
            ->where('dtr_month', Carbon::parse($date)->month)
            ->first();

        if ($certification?->certification_status === 'Certified') {
            return response()->json([
                'message' => 'This DTR is certified and locked. It must be formally reopened before attendance can change.',
            ], 422);
        }

        if ($certification?->certification_status === 'Submitted') {
            return response()->json([
                'message' => 'Return the submitted DTR for correction before changing its attendance records.',
            ], 422);
        }

        if ($validated['record_type'] === 'Time Entries') {
            $timeError = $this->validateCorrectionTimes($validated);

            if ($timeError) {
                return response()->json(['message' => $timeError], 422);
            }
        }

        $schedule = $this->effectiveSchedule($personnel->personnel_id, $date);
        $user = $request->user();
        $result = DB::transaction(function () use (
            $personnel,
            $schedule,
            $date,
            $validated,
            $user
        ): AttendanceRecord {
            $record = AttendanceRecord::query()
                ->where('personnel_id', $personnel->personnel_id)
                ->whereDate('attendance_date', $date)
                ->lockForUpdate()
                ->first();
            $isNew = ! $record;
            $record ??= new AttendanceRecord([
                'personnel_id' => $personnel->personnel_id,
                'attendance_date' => $date,
                'created_by' => $user->user_id,
            ]);
            $trackedFields = [
                'morning_time_in',
                'morning_time_out',
                'afternoon_time_in',
                'afternoon_time_out',
                'attendance_status',
                'total_work_minutes',
                'late_minutes',
                'undertime_minutes',
                'is_verified',
                'remarks',
            ];
            $oldValues = $isNew ? null : $record->only($trackedFields);

            $record->schedule_id = $schedule?->schedule_id;
            $record->record_source = 'Manual';
            $record->remarks = $validated['reason'];
            $record->is_verified = true;
            $record->verified_by = $user->user_id;
            $record->verified_at = now();

            if ($validated['record_type'] === 'Time Entries') {
                foreach ([
                    'morning_time_in',
                    'morning_time_out',
                    'afternoon_time_in',
                    'afternoon_time_out',
                ] as $field) {
                    $record->{$field} = filled($validated[$field] ?? null)
                        ? Carbon::parse($date.' '.$validated[$field], config('app.timezone'))
                        : null;
                }

                $record->attendance_status = 'Incomplete';
                $record->late_minutes = 0;
                $record->undertime_minutes = 0;
                $this->recalculate($record, $schedule, Carbon::parse($date)->endOfDay());
            } else {
                $record->morning_time_in = null;
                $record->morning_time_out = null;
                $record->afternoon_time_in = null;
                $record->afternoon_time_out = null;
                $record->attendance_status = $validated['record_type'];
                $record->total_work_minutes = 0;
                $record->late_minutes = 0;
                $record->undertime_minutes = 0;
            }

            $record->save();

            AttendanceChangeLog::create([
                'attendance_id' => $record->attendance_id,
                'changed_by' => $user->user_id,
                'action_type' => $isNew ? 'Created' : 'Updated',
                'old_values' => $oldValues,
                'new_values' => $record->only($trackedFields),
                'reason' => $validated['reason'],
            ]);

            return $record->fresh(['schedule']);
        });

        return response()->json([
            'message' => 'Attendance correction saved, verified, and recorded in the audit trail.',
            'data' => $this->formatAttendance(
                $result,
                $result->schedule,
                Carbon::parse($date)->endOfDay(),
                false
            ),
        ]);
    }

    private function validateCorrectionTimes(array $values): ?string
    {
        $morningIn = $values['morning_time_in'] ?? null;
        $morningOut = $values['morning_time_out'] ?? null;
        $afternoonIn = $values['afternoon_time_in'] ?? null;
        $afternoonOut = $values['afternoon_time_out'] ?? null;

        if ((bool) $morningIn !== (bool) $morningOut) {
            return 'Morning time in and time out must both be provided.';
        }

        if ((bool) $afternoonIn !== (bool) $afternoonOut) {
            return 'Afternoon time in and time out must both be provided.';
        }

        if (! $morningIn && ! $afternoonIn) {
            return 'Provide at least one complete morning or afternoon attendance session.';
        }

        if ($morningIn && $morningOut && $morningOut <= $morningIn) {
            return 'Morning time out must be later than morning time in.';
        }

        if ($afternoonIn && $afternoonOut && $afternoonOut <= $afternoonIn) {
            return 'Afternoon time out must be later than afternoon time in.';
        }

        return null;
    }

    private function effectiveSchedule(int $personnelId, string $date): ?WorkSchedule
    {
        $assignment = PersonnelSchedule::query()
            ->with('schedule')
            ->where('personnel_id', $personnelId)
            ->whereDate('effective_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date);
            })
            ->latest('effective_from')
            ->first();

        return $assignment?->schedule;
    }

    private function recalculate(
        AttendanceRecord $record,
        ?WorkSchedule $schedule,
        ?Carbon $referenceTime = null
    ): void {
        $morningMinutes = $record->morning_time_in && $record->morning_time_out
            ? max(0, $record->morning_time_in->diffInMinutes($record->morning_time_out))
            : 0;
        $afternoonMinutes = $record->afternoon_time_in && $record->afternoon_time_out
            ? max(0, $record->afternoon_time_in->diffInMinutes($record->afternoon_time_out))
            : 0;
        $overtimeMinutes = $record->overtime_time_in && $record->overtime_time_out
            ? max(0, $record->overtime_time_in->diffInMinutes($record->overtime_time_out))
            : 0;

        $record->total_work_minutes = $morningMinutes + $afternoonMinutes;
        $record->overtime_minutes = $overtimeMinutes;
        $record->attendance_status = $this->determineAttendanceStatus(
            $record,
            $schedule,
            $referenceTime ?? now()
        );

        if (! $schedule) {
            return;
        }

        if ($record->morning_time_in) {
            $record->late_minutes = 0;
            $scheduledStart = Carbon::parse(
                $record->attendance_date->format('Y-m-d').' '.$schedule->morning_start,
                $record->morning_time_in->getTimezone()
            )->addMinutes($schedule->grace_period_minutes);
            $record->late_minutes = $record->morning_time_in->greaterThan($scheduledStart)
                ? $scheduledStart->diffInMinutes($record->morning_time_in)
                : 0;
        }

        if ($record->afternoon_time_out) {
            $scheduledEnd = Carbon::parse(
                $record->attendance_date->format('Y-m-d').' '.$schedule->afternoon_end
            );
            $record->undertime_minutes = $record->afternoon_time_out->lessThan($scheduledEnd)
                ? $record->afternoon_time_out->diffInMinutes($scheduledEnd)
                : 0;
        }
    }

    private function determineAvailableAction(
        ?AttendanceRecord $record,
        ?WorkSchedule $schedule,
        Carbon $now,
        bool $isSpecialWorkingDay = false
    ): array {
        if (! $schedule) {
            return [
                'action' => null,
                'message' => 'No active work schedule is assigned to this personnel record.',
            ];
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
            return [
                'action' => null,
                'message' => 'This record is marked as '.$record->attendance_status.' and cannot accept a time log.',
            ];
        }

        $workdayField = strtolower($now->format('l'));

        if (! $schedule->{$workdayField} && ! $isSpecialWorkingDay) {
            return [
                'action' => null,
                'message' => 'Today is not enabled as a duty day in the assigned work schedule.',
            ];
        }

        if ($record?->is_complete) {
            return [
                'action' => null,
                'message' => "Today's required time-in and time-out entries are already complete.",
            ];
        }

        $windows = [
            [
                'action' => 'morning_time_in',
                'label' => 'Morning Time In',
                'start' => $schedule->morning_time_in_start,
                // A late arrival can still time in until the morning session closes.
                'end' => $schedule->morning_time_out_end,
                'requires' => null,
            ],
            [
                'action' => 'morning_time_out',
                'label' => 'Morning Time Out',
                'start' => $schedule->morning_time_out_start,
                'end' => $schedule->morning_time_out_end,
                'requires' => 'morning_time_in',
            ],
            [
                'action' => 'afternoon_time_in',
                'label' => 'Afternoon Time In',
                'start' => $schedule->afternoon_time_in_start,
                'end' => $schedule->afternoon_time_in_end,
                'requires' => null,
            ],
            [
                'action' => 'afternoon_time_out',
                'label' => 'Afternoon Time Out',
                'start' => $schedule->afternoon_time_out_start,
                'end' => $schedule->afternoon_time_out_end,
                'requires' => 'afternoon_time_in',
            ],
        ];

        foreach ($windows as $window) {
            $start = Carbon::parse(
                $now->toDateString().' '.$window['start'],
                $now->getTimezone()
            );
            $end = Carbon::parse(
                $now->toDateString().' '.$window['end'],
                $now->getTimezone()
            );

            if (! $now->betweenIncluded($start, $end)) {
                continue;
            }

            if ($record?->{$window['action']}) {
                continue;
            }

            if ($window['requires'] && ! $record?->{$window['requires']}) {
                return [
                    'action' => null,
                    'message' => $window['label'].' requires a matching time-in entry.',
                ];
            }

            return [
                'action' => $window['action'],
                'message' => $window['label'].' is available now.',
            ];
        }

        foreach ($windows as $window) {
            $start = Carbon::parse(
                $now->toDateString().' '.$window['start'],
                $now->getTimezone()
            );

            if ($start->greaterThan($now) && ! $record?->{$window['action']}) {
                return [
                    'action' => null,
                    'message' => $window['label'].' opens at '.$start->format('h:i A').'.',
                ];
            }
        }

        return [
            'action' => null,
            'message' => 'All attendance windows have closed for today.',
        ];
    }

    private function determineAttendanceStatus(
        AttendanceRecord $record,
        ?WorkSchedule $schedule,
        Carbon $referenceTime
    ): string {
        if (in_array($record->attendance_status, [
            'Absent',
            'Leave',
            'Holiday',
            'Rest Day',
            'Official Business',
            'Work From Home',
        ], true)) {
            return $record->attendance_status;
        }

        $morningComplete = (bool) $record->morning_time_in && (bool) $record->morning_time_out;
        $afternoonComplete = (bool) $record->afternoon_time_in && (bool) $record->afternoon_time_out;
        $hasMorningEntry = (bool) $record->morning_time_in || (bool) $record->morning_time_out;
        $hasAfternoonEntry = (bool) $record->afternoon_time_in || (bool) $record->afternoon_time_out;

        if ($morningComplete && $afternoonComplete) {
            return 'Present';
        }

        if ($afternoonComplete && ! $hasMorningEntry) {
            return 'Half Day';
        }

        if ($morningComplete && ! $hasAfternoonEntry && $schedule?->afternoon_time_in_end) {
            $afternoonCutoff = Carbon::parse(
                $referenceTime->toDateString().' '.$schedule->afternoon_time_in_end,
                $referenceTime->getTimezone()
            );

            if ($referenceTime->greaterThan($afternoonCutoff)) {
                return 'Half Day';
            }
        }

        return 'Incomplete';
    }

    private function syncCalculatedStatus(
        AttendanceRecord $record,
        ?WorkSchedule $schedule,
        Carbon $referenceTime
    ): void {
        $status = $this->determineAttendanceStatus($record, $schedule, $referenceTime);

        if ($record->attendance_status !== $status) {
            $record->forceFill(['attendance_status' => $status])->save();
        }
    }

    private function halfDayPeriod(AttendanceRecord $record, string $displayStatus): ?string
    {
        if ($displayStatus !== 'Half Day') {
            return null;
        }

        $morningComplete = (bool) $record->morning_time_in && (bool) $record->morning_time_out;

        return $morningComplete ? 'Morning' : 'Afternoon';
    }

    private function formatDailyPersonnel(
        Personnel $personnel,
        ?AttendanceRecord $record,
        ?Holiday $holiday,
        ?WorkSchedule $schedule,
        Carbon $referenceTime,
        bool $allowAction,
        bool $isSpecialWorkingDay = false
    ): array {
        $formatted = $record ? $this->formatAttendance(
            $record,
            $schedule,
            $referenceTime,
            $allowAction,
            $isSpecialWorkingDay
        ) : [
            'attendance_id' => null,
            'morning_time_in' => null,
            'morning_time_out' => null,
            'afternoon_time_in' => null,
            'afternoon_time_out' => null,
            'attendance_status' => null,
            'display_status' => $holiday ? 'Holiday' : 'Not Started',
            'total_work_minutes' => 0,
            'late_minutes' => 0,
            'is_late' => false,
            'undertime_minutes' => 0,
            'is_verified' => false,
            'attendance_complete' => false,
            'day_closed' => false,
            'half_day_period' => null,
            ...$this->formatEligibility(
                $allowAction
                    ? $this->determineAvailableAction(null, $schedule, $referenceTime, $isSpecialWorkingDay)
                    : ['action' => null, 'message' => 'Historical attendance is view only.']
            ),
            'schedule' => $this->formatSchedule($schedule),
        ];

        return [
            'personnel_id' => $personnel->personnel_id,
            'employee_number' => $personnel->employee_number,
            'full_name' => $personnel->full_name,
            'personnel_type' => $personnel->personnel_type,
            'position_title' => $personnel->position_title,
            'department' => $personnel->department ? [
                'department_code' => $personnel->department->department_code,
                'department_name' => $personnel->department->department_name,
            ] : null,
            ...$formatted,
        ];
    }

    private function formatAttendance(
        AttendanceRecord $record,
        ?WorkSchedule $schedule = null,
        ?Carbon $referenceTime = null,
        bool $allowAction = true,
        bool $isSpecialWorkingDay = false
    ): array {
        $schedule ??= $record->schedule;
        $referenceTime ??= now();
        $displayStatus = $this->determineAttendanceStatus($record, $schedule, $referenceTime);
        $eligibility = $allowAction
            ? $this->determineAvailableAction($record, $schedule, $referenceTime, $isSpecialWorkingDay)
            : ['action' => null, 'message' => 'Historical attendance is view only.'];

        return [
            'attendance_id' => $record->attendance_id,
            'morning_time_in' => $record->morning_time_in?->toISOString(),
            'morning_time_out' => $record->morning_time_out?->toISOString(),
            'afternoon_time_in' => $record->afternoon_time_in?->toISOString(),
            'afternoon_time_out' => $record->afternoon_time_out?->toISOString(),
            'overtime_time_in' => $record->overtime_time_in?->toISOString(),
            'overtime_time_out' => $record->overtime_time_out?->toISOString(),
            'attendance_status' => $record->attendance_status,
            'display_status' => $displayStatus,
            'total_work_minutes' => $record->total_work_minutes,
            'late_minutes' => $record->late_minutes,
            'is_late' => $record->late_minutes > 0,
            'undertime_minutes' => $record->undertime_minutes,
            'overtime_minutes' => $record->overtime_minutes,
            'remarks' => $record->remarks,
            'record_source' => $record->record_source,
            'is_verified' => $record->is_verified,
            'attendance_complete' => $record->is_complete,
            'day_closed' => in_array($displayStatus, ['Present', 'Half Day'], true),
            'half_day_period' => $this->halfDayPeriod($record, $displayStatus),
            ...$this->formatEligibility($eligibility),
            'schedule' => $this->formatSchedule($schedule),
        ];
    }

    private function formatEligibility(array $eligibility): array
    {
        $action = $eligibility['action'];

        return [
            'next_action' => $action,
            'next_action_label' => $action
                ? str($action)->replace('_', ' ')->title()->toString()
                : null,
            'action_message' => $eligibility['message'],
        ];
    }

    private function formatSchedule(?WorkSchedule $schedule): ?array
    {
        if (! $schedule) {
            return null;
        }

        return [
            'schedule_id' => $schedule->schedule_id,
            'schedule_name' => $schedule->schedule_name,
            'morning_start' => $schedule->morning_start,
            'morning_end' => $schedule->morning_end,
            'afternoon_start' => $schedule->afternoon_start,
            'afternoon_end' => $schedule->afternoon_end,
            'morning_time_in_start' => $schedule->morning_time_in_start,
            'morning_time_in_end' => $schedule->morning_time_in_end,
            'morning_time_out_start' => $schedule->morning_time_out_start,
            'morning_time_out_end' => $schedule->morning_time_out_end,
            'afternoon_time_in_start' => $schedule->afternoon_time_in_start,
            'afternoon_time_in_end' => $schedule->afternoon_time_in_end,
            'afternoon_time_out_start' => $schedule->afternoon_time_out_start,
            'afternoon_time_out_end' => $schedule->afternoon_time_out_end,
            'required_minutes_per_day' => $schedule->required_minutes_per_day,
        ];
    }
}
