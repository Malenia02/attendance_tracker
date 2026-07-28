<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceChangeLog;
use App\Models\AttendanceCorrectionRequest;
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
use Illuminate\Support\Str;
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
                'missing_time_out' => $allRows->where('has_missing_time_out', true)->count(),
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

    public function correctionRequests(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['Pending', 'Approved', 'Rejected', 'Cancelled'])],
        ]);
        $user = $request->user();
        $date = Carbon::parse($validated['date'] ?? now()->toDateString())->toDateString();
        $managerRoles = ['Administrator', 'HR', 'Supervisor'];
        $visiblePersonnelIds = in_array($user->user_role, $managerRoles, true)
            ? PersonnelAccess::scope(Personnel::query(), $user)->select('personnel_id')
            : Personnel::query()->whereKey($user->personnel_id ?? -1)->select('personnel_id');

        $requests = AttendanceCorrectionRequest::query()
            ->with([
                'personnel:personnel_id,department_id,employee_number,first_name,middle_name,last_name,suffix',
                'personnel.department:department_id,department_code,department_name',
                'submittedBy:user_id,username',
                'reviewedBy:user_id,username',
            ])
            ->whereDate('attendance_date', $date)
            ->whereIn('personnel_id', $visiblePersonnelIds)
            ->when(
                $validated['status'] ?? null,
                fn ($query, string $status) => $query->where('request_status', $status)
            )
            ->orderByRaw("CASE WHEN request_status = 'Pending' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (AttendanceCorrectionRequest $correctionRequest) => $this->formatCorrectionRequest($correctionRequest));

        return response()->json([
            'data' => $requests,
            'can_review' => in_array($user->user_role, ['Administrator', 'HR'], true),
        ]);
    }

    public function submitCorrectionRequest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'attendance_date' => ['required', 'date', 'before_or_equal:today'],
            'missing_field' => ['required', Rule::in(['morning_time_out', 'afternoon_time_out'])],
            'proposed_time' => ['required', 'date_format:H:i'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $user = $request->user();

        if (! $user->personnel_id) {
            return response()->json([
                'message' => 'Your account is not linked to a personnel record.',
            ], 422);
        }

        $date = Carbon::parse($validated['attendance_date'])->toDateString();
        $attendance = AttendanceRecord::query()
            ->with('schedule')
            ->where('personnel_id', $user->personnel_id)
            ->whereDate('attendance_date', $date)
            ->first();

        if (! $attendance) {
            return response()->json([
                'message' => 'No attendance record exists for this date.',
            ], 422);
        }

        $referenceTime = $attendance->attendance_date->isToday()
            ? now()
            : $attendance->attendance_date->copy()->endOfDay();
        $missingFields = collect(
            $this->missingTimeOutEntries($attendance, $attendance->schedule, $referenceTime)
        )->pluck('field');

        if (! $missingFields->contains($validated['missing_field'])) {
            return response()->json([
                'message' => 'That time-out is not currently flagged as missing.',
            ], 422);
        }

        if ($timeError = $this->validateProposedTime(
            $attendance,
            $validated['missing_field'],
            $validated['proposed_time'],
            now()
        )) {
            return response()->json(['message' => $timeError], 422);
        }

        $result = DB::transaction(function () use (
            $attendance,
            $validated,
            $date,
            $user,
            $request
        ): array {
            $lockedAttendance = AttendanceRecord::query()
                ->whereKey($attendance->attendance_id)
                ->lockForUpdate()
                ->firstOrFail();
            $pendingKey = $lockedAttendance->attendance_id.':'.$validated['missing_field'];
            $existing = AttendanceCorrectionRequest::query()
                ->where('pending_key', $pendingKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return ['error' => 'A correction request for this missing time-out is already pending.'];
            }

            $correctionRequest = AttendanceCorrectionRequest::create([
                'attendance_id' => $lockedAttendance->attendance_id,
                'personnel_id' => $lockedAttendance->personnel_id,
                'submitted_by' => $user->user_id,
                'attendance_date' => $date,
                'missing_field' => $validated['missing_field'],
                'proposed_time' => $validated['proposed_time'],
                'reason' => $validated['reason'],
                'request_status' => 'Pending',
                'pending_key' => $pendingKey,
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            ]);

            return ['request' => $correctionRequest];
        });

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], 422);
        }

        return response()->json([
            'message' => 'Your missing time-out explanation was submitted for HR review.',
            'data' => $this->formatCorrectionRequest(
                $result['request']->load(['personnel.department', 'submittedBy', 'reviewedBy'])
            ),
        ], 201);
    }

    public function reviewCorrectionRequest(
        Request $request,
        AttendanceCorrectionRequest $correctionRequest
    ): JsonResponse {
        if (! in_array($request->user()->user_role, ['Administrator', 'HR'], true)) {
            return response()->json([
                'message' => 'Only an administrator or HR user may review correction requests.',
            ], 403);
        }

        $validated = $request->validate([
            'action' => ['required', Rule::in(['Approved', 'Rejected'])],
            'review_remarks' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validated['action'] === 'Rejected' && strlen(trim($validated['review_remarks'] ?? '')) < 10) {
            return response()->json([
                'message' => 'Provide a rejection reason of at least 10 characters.',
            ], 422);
        }

        $correctionRequest->loadMissing(['personnel', 'attendance.schedule']);

        if (
            ! $correctionRequest->personnel
            || ! PersonnelAccess::canAccess($request->user(), $correctionRequest->personnel)
        ) {
            return response()->json([
                'message' => 'This request is outside your authorized office scope.',
            ], 403);
        }

        if ($correctionRequest->request_status !== 'Pending') {
            return response()->json([
                'message' => 'This correction request has already been reviewed.',
            ], 422);
        }

        if ($validated['action'] === 'Approved') {
            $certification = DtrCertification::query()
                ->where('personnel_id', $correctionRequest->personnel_id)
                ->where('dtr_year', $correctionRequest->attendance_date->year)
                ->where('dtr_month', $correctionRequest->attendance_date->month)
                ->first();
            $certificationStatus = $certification?->certification_status;

            if (in_array($certificationStatus, ['Submitted', 'Certified'], true)) {
                return response()->json([
                    'message' => $certificationStatus === 'Certified'
                        ? 'This DTR is certified and locked. It must be formally reopened before approving the request.'
                        : 'Return the submitted DTR before approving this correction request.',
                ], 422);
            }

            if (! $this->isAuthorizedReopenedDate(
                $certification,
                $correctionRequest->attendance_date->toDateString()
            )) {
                return response()->json([
                    'message' => 'This date was not included in the approved DTR reopening request.',
                ], 422);
            }
        }

        $result = DB::transaction(function () use (
            $correctionRequest,
            $validated,
            $request
        ): array {
            $lockedRequest = AttendanceCorrectionRequest::query()
                ->whereKey($correctionRequest->attendance_correction_request_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRequest->request_status !== 'Pending') {
                return ['error' => 'This correction request has already been reviewed.'];
            }

            $attendance = AttendanceRecord::query()
                ->with('schedule')
                ->whereKey($lockedRequest->attendance_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($validated['action'] === 'Approved') {
                $missingFields = collect($this->missingTimeOutEntries(
                    $attendance,
                    $attendance->schedule,
                    $attendance->attendance_date->isToday()
                        ? now()
                        : $attendance->attendance_date->copy()->endOfDay()
                ))->pluck('field');

                if (! $missingFields->contains($lockedRequest->missing_field)) {
                    return ['error' => 'The missing time-out has already been resolved or is no longer eligible.'];
                }

                if ($timeError = $this->validateProposedTime(
                    $attendance,
                    $lockedRequest->missing_field,
                    $lockedRequest->proposed_time,
                    now()
                )) {
                    return ['error' => $timeError];
                }

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
                    'verified_by',
                    'verified_at',
                    'remarks',
                    'record_source',
                ];
                $oldValues = $attendance->only($trackedFields);
                $attendance->{$lockedRequest->missing_field} = Carbon::parse(
                    $attendance->attendance_date->toDateString().' '.$lockedRequest->proposed_time,
                    config('app.timezone')
                );
                $attendance->record_source = 'Manual';
                $attendance->remarks = Str::limit(
                    'Correction request #'.$lockedRequest->attendance_correction_request_id
                        .': '.$lockedRequest->reason,
                    255,
                    ''
                );
                $attendance->is_verified = false;
                $attendance->verified_by = null;
                $attendance->verified_at = null;
                $this->recalculate(
                    $attendance,
                    $attendance->schedule,
                    $attendance->attendance_date->copy()->endOfDay()
                );
                $attendance->save();

                AttendanceChangeLog::create([
                    'attendance_id' => $attendance->attendance_id,
                    'changed_by' => $request->user()->user_id,
                    'action_type' => 'Updated',
                    'old_values' => $oldValues,
                    'new_values' => $attendance->only($trackedFields),
                    'reason' => Str::limit(
                        'Approved employee correction request #'
                            .$lockedRequest->attendance_correction_request_id
                            .'. '.$lockedRequest->reason,
                        255,
                        ''
                    ),
                ]);
            }

            $lockedRequest->forceFill([
                'request_status' => $validated['action'],
                'pending_key' => null,
                'reviewed_by' => $request->user()->user_id,
                'reviewed_at' => now(),
                'review_remarks' => trim($validated['review_remarks'] ?? '') ?: null,
            ])->save();

            return ['request' => $lockedRequest];
        });

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], 422);
        }

        $approved = $validated['action'] === 'Approved';

        return response()->json([
            'message' => $approved
                ? 'Correction request approved and applied. A different authorized reviewer must verify the attendance record.'
                : 'Correction request rejected. The employee can review the decision and submit a new request.',
            'data' => $this->formatCorrectionRequest(
                $result['request']->load(['personnel.department', 'submittedBy', 'reviewedBy'])
            ),
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

        if (! $trustedQrScan && ! $user->personnel_id) {
            return response()->json([
                'message' => 'Your account is not linked to a personnel record. Link this system user to your personnel profile, or use the authorized QR Attendance kiosk.',
            ], 422);
        }

        if (! $trustedQrScan && (int) $user->personnel_id !== (int) $validated['personnel_id']) {
            return response()->json([
                'message' => 'You may only record attendance for your own personnel account. Use QR Attendance for another personnel member.',
            ], 403);
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

        $attendance->loadMissing(['personnel', 'schedule']);

        if (! $attendance->personnel || ! PersonnelAccess::canAccess($request->user(), $attendance->personnel)) {
            return response()->json([
                'message' => 'This attendance record is outside your assigned office scope.',
            ], 403);
        }

        if ((int) $request->user()->personnel_id === (int) $attendance->personnel_id) {
            return response()->json([
                'message' => 'You cannot verify your own attendance record. A different authorized reviewer is required.',
            ], 403);
        }

        $certification = DtrCertification::query()
            ->where('personnel_id', $attendance->personnel_id)
            ->where('dtr_year', $attendance->attendance_date->year)
            ->where('dtr_month', $attendance->attendance_date->month)
            ->first();
        $certificationStatus = $certification?->certification_status;

        if (in_array($certificationStatus, ['Submitted', 'Certified'], true)) {
            return response()->json([
                'message' => $certificationStatus === 'Certified'
                    ? 'This DTR is certified and locked.'
                    : 'Return the submitted DTR before changing attendance verification.',
            ], 422);
        }

        if (! $this->isAuthorizedReopenedDate(
            $certification,
            $attendance->attendance_date->toDateString()
        )) {
            return response()->json([
                'message' => 'This date was not included in the approved DTR reopening request.',
            ], 422);
        }

        if ($verificationError = $this->verificationBlockReason(
            $attendance,
            $request->user()->user_id,
            $attendance->attendance_date->isToday()
                ? now()
                : $attendance->attendance_date->copy()->endOfDay()
        )) {
            return response()->json(['message' => $verificationError], 422);
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

            if ((int) $request->user()->personnel_id === (int) $record->personnel_id) {
                return response()->json([
                    'message' => 'You cannot verify your own attendance record. A different authorized reviewer is required.',
                ], 403);
            }

            $certification = DtrCertification::query()
                ->where('personnel_id', $record->personnel_id)
                ->where('dtr_year', $record->attendance_date->year)
                ->where('dtr_month', $record->attendance_date->month)
                ->first();
            $certificationStatus = $certification?->certification_status;

            if (in_array($certificationStatus, ['Submitted', 'Certified'], true)) {
                return response()->json([
                    'message' => 'Return submitted DTRs before verifying attendance, and never modify certified DTRs.',
                ], 422);
            }

            if (! $this->isAuthorizedReopenedDate(
                $certification,
                $record->attendance_date->toDateString()
            )) {
                return response()->json([
                    'message' => 'One or more dates were not included in the approved DTR reopening request.',
                ], 422);
            }

            if ($verificationError = $this->verificationBlockReason(
                $record,
                $request->user()->user_id,
                $record->attendance_date->copy()->endOfDay()
            )) {
                return response()->json(['message' => $verificationError], 422);
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

        if (! PersonnelAccess::canAccess($request->user(), $personnel)) {
            return response()->json([
                'message' => 'This personnel record is outside your authorized office scope.',
            ], 403);
        }

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

        if (! $this->isAuthorizedReopenedDate($certification, $date)) {
            return response()->json([
                'message' => 'This date was not included in the approved DTR reopening request.',
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
                'verified_by',
                'verified_at',
                'remarks',
                'record_source',
            ];
            $oldValues = $isNew ? null : $record->only($trackedFields);

            $record->schedule_id = $schedule?->schedule_id;
            $record->record_source = 'Manual';
            $record->remarks = $validated['reason'];
            $record->is_verified = false;
            $record->verified_by = null;
            $record->verified_at = null;

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
            'message' => 'Attendance correction saved and audited. A different authorized reviewer must verify it before DTR certification.',
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

        if ($morningOut && $afternoonIn && $afternoonIn < $morningOut) {
            return 'Afternoon time in cannot be earlier than morning time out.';
        }

        return null;
    }

    private function validateProposedTime(
        AttendanceRecord $record,
        string $missingField,
        string $proposedTime,
        Carbon $referenceTime
    ): ?string {
        $timeInField = $missingField === 'morning_time_out'
            ? 'morning_time_in'
            : 'afternoon_time_in';
        $timeIn = $record->{$timeInField};

        if (! $timeIn) {
            return 'A proposed time-out requires its matching recorded time-in.';
        }

        $proposed = Carbon::parse(
            $record->attendance_date->toDateString().' '.$proposedTime,
            config('app.timezone')
        );

        if (! $proposed->greaterThan($timeIn)) {
            return 'The proposed time-out must be later than the recorded time-in.';
        }

        if (
            $missingField === 'morning_time_out'
            && $record->afternoon_time_in
            && $proposed->greaterThan($record->afternoon_time_in)
        ) {
            return 'The proposed morning time-out cannot be later than the recorded afternoon time-in.';
        }

        if ($record->attendance_date->isToday() && $proposed->greaterThan($referenceTime)) {
            return 'The proposed time-out cannot be in the future.';
        }

        return null;
    }

    private function verificationBlockReason(
        AttendanceRecord $record,
        int $reviewerId,
        Carbon $referenceTime
    ): ?string {
        if ($this->determineAttendanceStatus(
            $record,
            $record->schedule,
            $referenceTime
        ) === 'Incomplete') {
            return 'Incomplete entries, including missing time-outs, must be corrected before verification.';
        }

        if ($record->record_source !== 'Manual') {
            return null;
        }

        $latestCorrection = $record->changeLogs()
            ->whereIn('action_type', ['Created', 'Updated'])
            ->latest('attendance_change_log_id')
            ->first();

        if ($latestCorrection && (int) $latestCorrection->changed_by === $reviewerId) {
            return 'A different authorized reviewer must verify this manual correction.';
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

    private function missingTimeOutEntries(
        AttendanceRecord $record,
        ?WorkSchedule $schedule,
        Carbon $referenceTime
    ): array {
        $checks = [
            [
                'time_in' => 'morning_time_in',
                'time_out' => 'morning_time_out',
                'window_end' => $schedule?->morning_time_out_end,
                'label' => 'Morning time-out',
            ],
            [
                'time_in' => 'afternoon_time_in',
                'time_out' => 'afternoon_time_out',
                'window_end' => $schedule?->afternoon_time_out_end,
                'label' => 'Afternoon time-out',
            ],
        ];
        $missing = [];

        foreach ($checks as $check) {
            if (! $record->{$check['time_in']} || $record->{$check['time_out']}) {
                continue;
            }

            if ($check['window_end']) {
                $windowEnd = Carbon::parse(
                    $record->attendance_date->toDateString().' '.$check['window_end'],
                    $referenceTime->getTimezone()
                );

                if (! $referenceTime->greaterThan($windowEnd)) {
                    continue;
                }
            } elseif ($referenceTime->toDateString() <= $record->attendance_date->toDateString()) {
                continue;
            }

            $missing[] = [
                'field' => $check['time_out'],
                'label' => $check['label'],
            ];
        }

        return $missing;
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
            'has_missing_time_out' => false,
            'missing_time_out_entries' => [],
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
        $missingTimeOutEntries = $this->missingTimeOutEntries(
            $record,
            $schedule,
            $referenceTime
        );
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
            'has_missing_time_out' => $missingTimeOutEntries !== [],
            'missing_time_out_entries' => $missingTimeOutEntries,
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

    private function formatCorrectionRequest(
        AttendanceCorrectionRequest $correctionRequest
    ): array {
        return [
            'request_id' => $correctionRequest->attendance_correction_request_id,
            'attendance_id' => $correctionRequest->attendance_id,
            'personnel_id' => $correctionRequest->personnel_id,
            'attendance_date' => $correctionRequest->attendance_date->toDateString(),
            'missing_field' => $correctionRequest->missing_field,
            'missing_label' => $correctionRequest->missing_field === 'morning_time_out'
                ? 'Morning time-out'
                : 'Afternoon time-out',
            'proposed_time' => Carbon::parse($correctionRequest->proposed_time)->format('H:i'),
            'reason' => $correctionRequest->reason,
            'status' => $correctionRequest->request_status,
            'review_remarks' => $correctionRequest->review_remarks,
            'submitted_at' => $correctionRequest->created_at?->toISOString(),
            'reviewed_at' => $correctionRequest->reviewed_at?->toISOString(),
            'submitted_by' => $correctionRequest->submittedBy?->username,
            'reviewed_by' => $correctionRequest->reviewedBy?->username,
            'personnel' => $correctionRequest->personnel ? [
                'employee_number' => $correctionRequest->personnel->employee_number,
                'full_name' => $correctionRequest->personnel->full_name,
                'department_code' => $correctionRequest->personnel->department?->department_code,
            ] : null,
        ];
    }

    private function isAuthorizedReopenedDate(
        ?DtrCertification $certification,
        string $attendanceDate
    ): bool {
        if (! $certification || $certification->certification_status !== 'Reopened') {
            return true;
        }

        $approvedRequest = $certification->reopenRequests()
            ->where('request_status', 'Approved')
            ->latest('reviewed_at')
            ->first();

        return in_array($attendanceDate, $approvedRequest?->affected_dates ?? [], true);
    }
}
