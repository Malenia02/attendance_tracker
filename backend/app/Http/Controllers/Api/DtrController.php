<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceChangeLog;
use App\Models\AttendanceRecord;
use App\Models\DtrCertification;
use App\Models\DtrStatusLog;
use App\Models\Holiday;
use App\Models\Personnel;
use App\Models\PersonnelSchedule;
use App\Services\DtrDocumentGenerator;
use App\Support\PersonnelAccess;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
use ZipArchive;

class DtrController extends Controller
{
    private const MANAGER_ROLES = ['Administrator', 'HR', 'Supervisor', 'Encoder'];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['Draft', 'Submitted', 'Certified', 'Returned', 'Reopened'])],
        ]);

        $month = Carbon::createFromFormat('Y-m-d', ($validated['month'] ?? now()->format('Y-m')).'-01')
            ->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();
        $cutoff = $month->isSameMonth(now()) ? now()->endOfDay() : $monthEnd;
        $user = $request->user();
        $canManageOthers = PersonnelAccess::canManageOthers($user);

        $holidays = Holiday::query()
            ->whereBetween('holiday_date', [$month->toDateString(), $monthEnd->toDateString()])
            ->get()
            ->groupBy(fn (Holiday $holiday) => $holiday->holiday_date->toDateString());

        $personnel = Personnel::query()
            ->with([
                'department:department_id,department_code,department_name',
                'scheduleAssignments' => fn ($query) => $query
                    ->with('schedule')
                    ->whereDate('effective_from', '<=', $monthEnd)
                    ->where(fn ($query) => $query
                        ->whereNull('effective_to')
                        ->orWhereDate('effective_to', '>=', $month))
                    ->orderByDesc('effective_from'),
                'attendanceRecords' => fn ($query) => $query
                    ->whereBetween('attendance_date', [$month->toDateString(), $monthEnd->toDateString()])
                    ->orderBy('attendance_date'),
                'dtrCertifications' => fn ($query) => $query
                    ->with([
                        'statusLogs.changedBy:user_id,username',
                        'reopenRequests.requestedBy:user_id,username',
                        'reopenRequests.reviewedBy:user_id,username',
                        'versions.archivedBy:user_id,username',
                    ])
                    ->where('dtr_year', $month->year)
                    ->where('dtr_month', $month->month),
            ])
            ->where('status', 'Active')
            ->tap(fn ($query) => PersonnelAccess::scope($query, $user))
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
            ->get();

        $rows = $personnel
            ->map(fn (Personnel $person) => $this->buildPersonnelRow(
                $person,
                $month,
                $monthEnd,
                $cutoff,
                $holidays
            ))
            ->when(
                $validated['status'] ?? null,
                fn (Collection $rows, string $status) => $rows
                    ->where('certification.status', $status)
                    ->values()
            );

        return response()->json([
            'month' => $month->format('Y-m'),
            'month_label' => $month->format('F Y'),
            'timezone' => config('app.timezone'),
            'can_manage_others' => $canManageOthers,
            'can_certify' => in_array($user->user_role, ['Administrator', 'HR', 'Supervisor'], true),
            'can_verify_attendance' => in_array($user->user_role, ['Administrator', 'HR', 'Supervisor'], true),
            'can_correct_attendance' => in_array($user->user_role, ['Administrator', 'HR'], true),
            'can_generate' => $user->user_role === 'Administrator',
            'can_request_reopen' => in_array($user->user_role, ['Administrator', 'HR'], true),
            'can_approve_reopen' => $user->user_role === 'Administrator',
            'current_user_id' => $user->user_id,
            'data' => $rows,
            'summary' => [
                'personnel' => $rows->count(),
                'ready' => $rows->where('is_ready', true)->count(),
                'needs_attention' => $rows->where('is_ready', false)->count(),
                'certified' => $rows->where('certification.status', 'Certified')->count(),
                'late_occurrences' => $rows->sum('late_days'),
                'half_days' => $rows->sum('half_days'),
            ],
        ]);
    }

    public function updateStatus(Request $request, Personnel $personnel): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'status' => ['required', Rule::in(['Draft', 'Submitted', 'Certified', 'Returned'])],
            'remarks' => ['nullable', 'string', 'max:255'],
        ]);
        $user = $request->user();
        $status = $validated['status'];
        $isOwnRecord = (int) $user->personnel_id === (int) $personnel->personnel_id;

        if (! PersonnelAccess::canAccess($user, $personnel)) {
            return response()->json(['message' => 'This DTR is outside your assigned office scope.'], 403);
        }

        if ($status === 'Certified' || $status === 'Returned') {
            if (! in_array($user->user_role, ['Administrator', 'HR', 'Supervisor'], true)) {
                return response()->json(['message' => 'You do not have permission to certify or return DTRs.'], 403);
            }
        } elseif (! $isOwnRecord && ! in_array($user->user_role, self::MANAGER_ROLES, true)) {
            return response()->json(['message' => 'You may only update your own DTR.'], 403);
        }

        if ($status === 'Certified' && $isOwnRecord) {
            return response()->json([
                'message' => 'You cannot certify your own DTR. A different authorized reviewer is required.',
            ], 403);
        }

        if ($status === 'Returned' && blank($validated['remarks'] ?? null)) {
            return response()->json(['message' => 'Please provide a reason when returning a DTR.'], 422);
        }

        [$year, $month] = array_map('intval', explode('-', $validated['month']));
        $certificationKey = [
            'personnel_id' => $personnel->personnel_id,
            'dtr_year' => $year,
            'dtr_month' => $month,
        ];
        DtrCertification::query()->firstOrCreate(
            $certificationKey,
            ['certification_status' => 'Draft']
        );
        $monitorRow = null;

        if (in_array($status, ['Submitted', 'Certified'], true)) {
            $monitorRequest = Request::create('/api/dtr', 'GET', ['month' => $validated['month']]);
            $monitorRequest->setUserResolver(fn () => $user);
            $monitorData = $this->index($monitorRequest)->getData(true);
            $monitorRow = collect($monitorData['data'])->firstWhere('personnel_id', $personnel->personnel_id);

            if (! $monitorRow || ! $monitorRow['is_ready']) {
                return response()->json([
                    'message' => 'Resolve all missing, incomplete, and unverified attendance records before submission or certification.',
                ], 422);
            }
        }

        $result = DB::transaction(function () use (
            $certificationKey,
            $status,
            $validated,
            $user,
            $request,
            $monitorRow
        ): array {
            $certification = DtrCertification::query()
                ->where($certificationKey)
                ->lockForUpdate()
                ->firstOrFail();
            $previousStatus = $certification->certification_status ?? 'Draft';
            $correctedByCertifier = $status === 'Certified'
                && $this->certifierChangedAmendedAttendance($certification, $user->user_id);
            $transitionError = match (true) {
                $previousStatus === 'Certified' => 'This DTR is certified and locked. A separate authorized reopening process is required.',
                $status === $previousStatus => "This DTR is already {$status}.",
                $status === 'Draft' => 'A submitted or returned DTR cannot be moved back to Draft.',
                $status === 'Submitted' && ! in_array($previousStatus, ['Draft', 'Returned', 'Reopened'], true) => 'Only draft, returned, or reopened DTRs may be submitted.',
                $status === 'Returned' && $previousStatus !== 'Submitted' => 'Only submitted DTRs may be returned for correction.',
                $status === 'Certified' && $previousStatus !== 'Submitted' => 'The DTR must be submitted before certification.',
                $status === 'Certified' && $certification->prepared_by === $user->user_id => 'The person who submitted a DTR cannot also certify it.',
                $correctedByCertifier => 'The person who corrected an amended attendance entry cannot certify that DTR version.',
                default => null,
            };

            if ($transitionError) {
                return ['error' => $transitionError];
            }

            $certification->certification_status = $status;
            $certification->remarks = $validated['remarks'] ?? null;

            if ($status === 'Submitted') {
                $certification->prepared_by = $user->user_id;
                $certification->prepared_at = now();
                $certification->certified_by = null;
                $certification->certified_at = null;
                $certification->certified_snapshot = null;
                $certification->certified_hash = null;
            } elseif ($status === 'Certified') {
                $snapshot = $monitorRow;
                $encodedSnapshot = json_encode(
                    $snapshot,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );
                $certification->certified_by = $user->user_id;
                $certification->certified_at = now();
                $certification->certified_snapshot = $snapshot;
                $certification->certified_hash = hash_hmac(
                    'sha256',
                    $encodedSnapshot,
                    (string) config('attendance.dtr_signing_key')
                );
            } elseif ($status === 'Returned') {
                $certification->certified_by = null;
                $certification->certified_at = null;
                $certification->certified_snapshot = null;
                $certification->certified_hash = null;
            }

            $certification->save();

            DtrStatusLog::create([
                'dtr_certification_id' => $certification->dtr_certification_id,
                'changed_by' => $user->user_id,
                'from_status' => $previousStatus,
                'to_status' => $status,
                'remarks' => $validated['remarks'] ?? null,
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                'request_id' => (string) Str::uuid(),
            ]);

            return [
                'certification' => $certification,
                'previous_status' => $previousStatus,
            ];
        });

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], 422);
        }

        $certification = $result['certification'];
        $previousStatus = $result['previous_status'];
        $wasResubmitted = in_array($previousStatus, ['Returned', 'Reopened'], true)
            && $status === 'Submitted';

        return response()->json([
            'message' => $wasResubmitted
                ? 'DTR corrections submitted for independent review.'
                : 'DTR status updated to '.$status.'.',
            'certification' => $this->formatCertification(
                $certification->fresh([
                    'statusLogs.changedBy:user_id,username',
                    'reopenRequests.requestedBy:user_id,username',
                    'reopenRequests.reviewedBy:user_id,username',
                    'versions.archivedBy:user_id,username',
                ])
            ),
        ]);
    }

    public function generate(Request $request, DtrDocumentGenerator $generator): BinaryFileResponse|JsonResponse
    {
        if ($request->user()->user_role !== 'Administrator') {
            return response()->json([
                'message' => 'Only an administrator may generate official DTR documents.',
            ], 403);
        }

        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'personnel_ids' => ['nullable', 'array', 'max:100'],
            'personnel_ids.*' => ['integer', 'distinct', 'exists:personnel,personnel_id'],
        ]);
        $monitorRequest = Request::create('/api/dtr', 'GET', ['month' => $validated['month']]);
        $monitorRequest->setUserResolver(fn () => $request->user());
        $monitorData = $this->index($monitorRequest)->getData(true);
        $reports = collect($monitorData['data']);
        $requestedIds = collect($validated['personnel_ids'] ?? [])->map(fn ($id) => (int) $id);

        if ($requestedIds->isNotEmpty()) {
            $reports = $reports
                ->filter(fn (array $row) => $requestedIds->contains((int) $row['personnel_id']))
                ->values();

            if ($reports->count() !== $requestedIds->count()) {
                return response()->json([
                    'message' => 'One or more selected personnel DTR records are unavailable for this month.',
                ], 422);
            }
        }

        $uncertified = $reports->reject(
            fn (array $row) => ($row['certification']['status'] ?? 'Draft') === 'Certified'
        );

        if ($requestedIds->isNotEmpty() && $uncertified->isNotEmpty()) {
            $names = $uncertified->pluck('full_name')->take(3)->implode(', ');
            $remaining = max(0, $uncertified->count() - 3);

            return response()->json([
                'message' => 'Only certified DTRs can be generated. Certify '
                    .$names
                    .($remaining ? " and {$remaining} more" : '')
                    .' first.',
            ], 422);
        }

        $reports = $reports
            ->filter(fn (array $row) => ($row['certification']['status'] ?? 'Draft') === 'Certified')
            ->values();

        if ($reports->isEmpty()) {
            return response()->json([
                'message' => 'No certified personnel DTR records are available for the selected month.',
            ], 422);
        }

        $certifications = DtrCertification::query()
            ->where('dtr_year', (int) substr($validated['month'], 0, 4))
            ->where('dtr_month', (int) substr($validated['month'], 5, 2))
            ->whereIn('personnel_id', $reports->pluck('personnel_id'))
            ->get()
            ->keyBy('personnel_id');
        $invalidSnapshots = collect();
        $reports = $reports->map(function (array $report) use ($certifications, $invalidSnapshots): array {
            $certification = $certifications->get($report['personnel_id']);

            if (! $certification?->certified_snapshot) {
                return $report;
            }

            $encodedSnapshot = json_encode(
                $certification->certified_snapshot,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            $expectedHash = hash_hmac(
                'sha256',
                $encodedSnapshot,
                (string) config('attendance.dtr_signing_key')
            );

            if (! hash_equals((string) $certification->certified_hash, $expectedHash)) {
                $invalidSnapshots->push($report['full_name']);

                return $report;
            }

            return [
                ...$certification->certified_snapshot,
                'certification' => $report['certification'],
            ];
        });

        if ($invalidSnapshots->isNotEmpty()) {
            return response()->json([
                'message' => 'A certified DTR integrity check failed for '.$invalidSnapshots->implode(', ').'.',
            ], 409);
        }

        $generatedDirectory = storage_path('app/generated-dtr');
        File::ensureDirectoryExists($generatedDirectory);
        $batchId = Str::uuid()->toString();
        $documents = [];

        try {
            foreach ($reports as $report) {
                $downloadName = $this->dtrFileName($report, $validated['month']);
                $path = $generatedDirectory.DIRECTORY_SEPARATOR
                    .$batchId.'-'.$downloadName;
                $documents[] = [
                    'path' => $path,
                    'name' => $downloadName,
                ];
                $generator->generate([$report], $path);
            }
        } catch (Throwable $exception) {
            File::delete(collect($documents)->pluck('path')->all());
            throw $exception;
        }

        if (count($documents) === 1) {
            return response()
                ->download($documents[0]['path'], $documents[0]['name'])
                ->deleteFileAfterSend(true);
        }

        $zipPath = $generatedDirectory.DIRECTORY_SEPARATOR."DTR-{$validated['month']}-{$batchId}.zip";
        $archive = new ZipArchive;

        if ($archive->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            foreach ($documents as $document) {
                File::delete($document['path']);
            }

            return response()->json(['message' => 'The DTR package could not be created.'], 500);
        }

        foreach ($documents as $document) {
            $archive->addFile($document['path'], $document['name']);
        }

        $archive->close();
        File::delete(collect($documents)->pluck('path')->all());

        return response()
            ->download($zipPath, "Certified-DTR-{$validated['month']}.zip")
            ->deleteFileAfterSend(true);
    }

    private function dtrFileName(array $report, string $month): string
    {
        $personnelName = Str::of($report['full_name'] ?? '')
            ->ascii()
            ->replaceMatches('/[^A-Za-z0-9]+/', '-')
            ->trim('-')
            ->toString();

        if ($personnelName === '') {
            $personnelName = 'Personnel-'.$report['personnel_id'];
        }

        $version = (int) ($report['certification']['version_number'] ?? 1);
        $amended = $version > 1 ? "-Amended-v{$version}" : '';

        return "DTR-{$personnelName}-{$month}{$amended}.docx";
    }

    private function buildPersonnelRow(
        Personnel $person,
        Carbon $month,
        Carbon $monthEnd,
        Carbon $cutoff,
        Collection $holidays
    ): array {
        $records = $person->attendanceRecords->keyBy(
            fn (AttendanceRecord $record) => $record->attendance_date->toDateString()
        );
        $daily = collect();

        foreach (CarbonPeriod::create($month, $monthEnd) as $date) {
            if ($date->greaterThan($cutoff)) {
                continue;
            }

            if ($person->employment_start_date && $date->lessThan($person->employment_start_date)) {
                continue;
            }

            if ($person->employment_end_date && $date->greaterThan($person->employment_end_date)) {
                continue;
            }

            $assignment = $person->scheduleAssignments->first(fn (PersonnelSchedule $assignment) => $assignment->effective_from->lte($date)
                && (! $assignment->effective_to || $assignment->effective_to->gte($date))
            );
            $schedule = $assignment?->schedule;
            $dayField = strtolower($date->format('l'));
            $dateEvents = $holidays->get($date->toDateString(), collect());
            $holiday = $this->applicableHoliday($dateEvents, $person);
            $isSpecialWorkingDay = $dateEvents->contains(fn (Holiday $event) => $event->holiday_type === 'Special Working Holiday'
                && (! $event->department_id || $event->department_id === $person->department_id)
            );
            $isRegularDutyDay = (bool) ($schedule?->{$dayField});
            $isAuthorizedDutyDay = ! $isRegularDutyDay && $isSpecialWorkingDay && ! $holiday;
            $isDutyDay = ($isRegularDutyDay || $isAuthorizedDutyDay) && ! $holiday;
            $record = $records->get($date->toDateString());
            $attendanceRecord = $isDutyDay ? $record : null;
            $status = $holiday
                ? 'Holiday'
                : (! $isDutyDay
                    ? 'Rest Day'
                    : ($record
                        ? $this->displayStatus($record, $schedule, $date->copy()->endOfDay())
                        : 'Missing'));
            $dayType = $holiday
                ? 'Holiday'
                : ($isAuthorizedDutyDay
                    ? 'Authorized Duty Day'
                    : ($isDutyDay ? 'Regular Duty Day' : 'Rest Day'));

            $daily->push([
                'attendance_id' => $attendanceRecord?->attendance_id,
                'date' => $date->toDateString(),
                'day' => $date->format('D'),
                'day_number' => $date->day,
                'day_type' => $dayType,
                'is_duty_day' => $isDutyDay,
                'is_authorized_duty_day' => $isAuthorizedDutyDay,
                'holiday' => $holiday?->holiday_name,
                'status' => $status,
                'morning_time_in' => $attendanceRecord?->morning_time_in?->toISOString(),
                'morning_time_out' => $attendanceRecord?->morning_time_out?->toISOString(),
                'afternoon_time_in' => $attendanceRecord?->afternoon_time_in?->toISOString(),
                'afternoon_time_out' => $attendanceRecord?->afternoon_time_out?->toISOString(),
                'work_minutes' => $attendanceRecord?->total_work_minutes ?? 0,
                'late_minutes' => $attendanceRecord?->late_minutes ?? 0,
                'undertime_minutes' => $attendanceRecord?->undertime_minutes ?? 0,
                'is_verified' => (bool) $attendanceRecord?->is_verified,
            ]);
        }

        $expectedDays = $daily->where('is_duty_day', true)->count();
        $missingDays = $daily->where('status', 'Missing')->count();
        $incompleteDays = $daily->where('status', 'Incomplete')->count();
        $unverifiedDays = $daily
            ->filter(fn (array $day) => $day['is_duty_day']
                && ! in_array($day['status'], ['Missing', 'Holiday'], true)
                && ! $day['is_verified'])
            ->count();
        $resolvedDays = max(0, $expectedDays - $missingDays - $incompleteDays);
        $certification = $person->dtrCertifications->first();
        $primarySchedule = $person->scheduleAssignments->first()?->schedule;
        $officialHours = $this->formatOfficialHours($primarySchedule);

        return [
            'month_label' => $month->format('F Y'),
            'personnel_id' => $person->personnel_id,
            'employee_number' => $person->employee_number,
            'full_name' => $person->full_name,
            'personnel_type' => $person->personnel_type,
            'position_title' => $person->position_title,
            'official_hours' => $officialHours,
            'saturday_hours' => $primarySchedule?->saturday ? $officialHours : 'N/A',
            'department' => $person->department ? [
                'code' => $person->department->department_code,
                'name' => $person->department->department_name,
            ] : null,
            'expected_days' => $expectedDays,
            'recorded_days' => $expectedDays - $missingDays,
            'present_days' => $daily->where('status', 'Present')->count(),
            'half_days' => $daily->where('status', 'Half Day')->count(),
            'late_days' => $daily->where('late_minutes', '>', 0)->count(),
            'late_minutes' => $daily->sum('late_minutes'),
            'leave_days' => $daily->where('status', 'Leave')->count(),
            'absent_days' => $daily->whereIn('status', ['Absent', 'Missing'])->count(),
            'incomplete_days' => $incompleteDays,
            'unverified_days' => $unverifiedDays,
            'total_work_minutes' => $daily->sum('work_minutes'),
            'completion_percent' => $expectedDays
                ? (int) round(($resolvedDays / $expectedDays) * 100)
                : 100,
            'is_ready' => $expectedDays > 0
                && $missingDays === 0
                && $incompleteDays === 0
                && $unverifiedDays === 0,
            'issues' => [
                'missing' => $missingDays,
                'incomplete' => $incompleteDays,
                'unverified' => $unverifiedDays,
            ],
            'certification' => $this->formatCertification($certification),
            'daily_records' => $daily->values(),
        ];
    }

    private function applicableHoliday(Collection $holidays, Personnel $person): ?Holiday
    {
        return $holidays->first(fn (Holiday $holiday) => $holiday->holiday_type !== 'Special Working Holiday'
            && (! $holiday->department_id || $holiday->department_id === $person->department_id)
        );
    }

    private function displayStatus(
        AttendanceRecord $record,
        $schedule,
        Carbon $referenceTime
    ): string {
        if ($record->attendance_status !== 'Incomplete') {
            return $record->attendance_status;
        }

        $morningComplete = $record->morning_time_in && $record->morning_time_out;
        $afternoonComplete = $record->afternoon_time_in && $record->afternoon_time_out;
        $hasMorning = $record->morning_time_in || $record->morning_time_out;
        $hasAfternoon = $record->afternoon_time_in || $record->afternoon_time_out;

        if ($morningComplete && $afternoonComplete) {
            return 'Present';
        }

        if ($afternoonComplete && ! $hasMorning) {
            return 'Half Day';
        }

        if ($morningComplete && ! $hasAfternoon && $schedule?->afternoon_time_in_end) {
            $cutoff = Carbon::parse(
                $referenceTime->toDateString().' '.$schedule->afternoon_time_in_end,
                $referenceTime->getTimezone()
            );

            if ($referenceTime->greaterThan($cutoff)) {
                return 'Half Day';
            }
        }

        return 'Incomplete';
    }

    private function formatCertification(?DtrCertification $certification): array
    {
        $history = $certification?->statusLogs
            ?->map(fn (DtrStatusLog $log) => [
                'id' => $log->dtr_status_log_id,
                'from_status' => $log->from_status,
                'to_status' => $log->to_status,
                'remarks' => $log->remarks,
                'changed_by' => $log->changedBy?->username ?? 'System',
                'changed_at' => $log->created_at?->toISOString(),
            ])
            ->values()
            ->all() ?? [];
        $latestReturn = collect($history)->firstWhere('to_status', 'Returned');
        $reopenRequests = $certification?->reopenRequests
            ?->map(fn ($reopenRequest) => [
                'id' => $reopenRequest->dtr_reopen_request_id,
                'status' => $reopenRequest->request_status,
                'reason' => $reopenRequest->reason,
                'affected_dates' => $reopenRequest->affected_dates,
                'requested_by_id' => $reopenRequest->requested_by,
                'requested_by' => $reopenRequest->requestedBy?->username ?? 'Former user',
                'requested_at' => $reopenRequest->created_at?->toISOString(),
                'reviewed_by' => $reopenRequest->reviewedBy?->username,
                'reviewed_at' => $reopenRequest->reviewed_at?->toISOString(),
                'review_remarks' => $reopenRequest->review_remarks,
            ])
            ->values()
            ->all() ?? [];
        $versions = $certification?->versions
            ?->map(fn ($version) => [
                'id' => $version->dtr_certification_version_id,
                'version_number' => $version->version_number,
                'certified_at' => $version->certified_at?->toISOString(),
                'archived_at' => $version->archived_at?->toISOString(),
                'archived_by' => $version->archivedBy?->username ?? 'Former user',
                'archive_reason' => $version->archive_reason,
                'hash_prefix' => substr($version->certified_hash, 0, 12),
            ])
            ->values()
            ->all() ?? [];

        return [
            'id' => $certification?->dtr_certification_id,
            'status' => $certification?->certification_status ?? 'Draft',
            'version_number' => $certification?->version_number ?? 1,
            'is_amended' => ($certification?->version_number ?? 1) > 1,
            'remarks' => $certification?->remarks,
            'return_reason' => $latestReturn['remarks']
                ?? ($certification?->certification_status === 'Returned' ? $certification?->remarks : null),
            'prepared_at' => $certification?->prepared_at?->toISOString(),
            'certified_at' => $certification?->certified_at?->toISOString(),
            'history' => $history,
            'reopen_requests' => $reopenRequests,
            'versions' => $versions,
        ];
    }

    private function formatOfficialHours($schedule): string
    {
        if (! $schedule) {
            return '';
        }

        $format = fn (string $time) => Carbon::parse($time)->format('g:i');

        return $format($schedule->morning_start).'-'.$format($schedule->morning_end)
            .' / '.$format($schedule->afternoon_start).'-'.$format($schedule->afternoon_end);
    }

    private function certifierChangedAmendedAttendance(
        DtrCertification $certification,
        int $certifierId
    ): bool {
        if ($certification->version_number <= 1) {
            return false;
        }

        $approvedReopening = $certification->reopenRequests()
            ->where('request_status', 'Approved')
            ->latest('reviewed_at')
            ->first();
        $affectedDates = $approvedReopening?->affected_dates ?? [];

        if (! $approvedReopening || $affectedDates === []) {
            return false;
        }

        return AttendanceChangeLog::query()
            ->where('changed_by', $certifierId)
            ->whereIn('action_type', ['Created', 'Updated'])
            ->where('created_at', '>=', $approvedReopening->reviewed_at)
            ->whereHas('attendance', fn ($query) => $query
                ->where('personnel_id', $certification->personnel_id)
                ->whereIn('attendance_date', $affectedDates))
            ->exists();
    }
}
