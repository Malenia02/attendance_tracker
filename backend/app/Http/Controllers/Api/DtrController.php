<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\DtrCertification;
use App\Models\Holiday;
use App\Models\Personnel;
use App\Models\PersonnelSchedule;
use App\Services\DtrDocumentGenerator;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
            'status' => ['nullable', Rule::in(['Draft', 'Submitted', 'Certified', 'Returned'])],
        ]);

        $month = Carbon::createFromFormat('Y-m-d', ($validated['month'] ?? now()->format('Y-m')).'-01')
            ->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();
        $cutoff = $month->isSameMonth(now()) ? now()->endOfDay() : $monthEnd;
        $user = $request->user();
        $canManageOthers = in_array($user->user_role, self::MANAGER_ROLES, true);

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
                    ->where('dtr_year', $month->year)
                    ->where('dtr_month', $month->month),
            ])
            ->where('status', 'Active')
            ->when(! $canManageOthers, fn ($query) => $query->where('personnel_id', $user->personnel_id))
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
            'can_generate' => $user->user_role === 'Administrator',
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

        if ($status === 'Certified' || $status === 'Returned') {
            if (! in_array($user->user_role, ['Administrator', 'HR', 'Supervisor'], true)) {
                return response()->json(['message' => 'You do not have permission to certify or return DTRs.'], 403);
            }
        } elseif (! $isOwnRecord && ! in_array($user->user_role, self::MANAGER_ROLES, true)) {
            return response()->json(['message' => 'You may only update your own DTR.'], 403);
        }

        if ($status === 'Returned' && blank($validated['remarks'] ?? null)) {
            return response()->json(['message' => 'Please provide a reason when returning a DTR.'], 422);
        }

        if ($status === 'Submitted') {
            $monitorRequest = Request::create('/api/dtr', 'GET', ['month' => $validated['month']]);
            $monitorRequest->setUserResolver(fn () => $user);
            $monitorData = $this->index($monitorRequest)->getData(true);
            $monitorRow = collect($monitorData['data'])->firstWhere('personnel_id', $personnel->personnel_id);

            if (! $monitorRow || ! $monitorRow['is_ready']) {
                return response()->json([
                    'message' => 'Resolve all missing, incomplete, and unverified attendance records before submission.',
                ], 422);
            }
        }

        [$year, $month] = array_map('intval', explode('-', $validated['month']));
        $certification = DtrCertification::query()->firstOrNew([
            'personnel_id' => $personnel->personnel_id,
            'dtr_year' => $year,
            'dtr_month' => $month,
        ]);
        $previousStatus = $certification->certification_status ?? 'Draft';

        $certification->certification_status = $status;
        $certification->remarks = $validated['remarks'] ?? null;

        if ($status === 'Submitted') {
            $certification->prepared_by = $user->user_id;
            $certification->prepared_at = now();
            $certification->certified_by = null;
            $certification->certified_at = null;
        } elseif ($status === 'Certified') {
            if ($previousStatus !== 'Submitted') {
                return response()->json(['message' => 'The DTR must be submitted before certification.'], 422);
            }

            $certification->certified_by = $user->user_id;
            $certification->certified_at = now();
        } elseif ($status === 'Draft') {
            $certification->prepared_by = null;
            $certification->prepared_at = null;
            $certification->certified_by = null;
            $certification->certified_at = null;
        } elseif ($status === 'Returned') {
            $certification->certified_by = null;
            $certification->certified_at = null;
        }

        $certification->save();

        return response()->json([
            'message' => 'DTR status updated to '.$status.'.',
            'certification' => $this->formatCertification($certification),
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
        $archive = new ZipArchive();

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

        return "DTR-{$personnelName}-{$month}.docx";
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

            $assignment = $person->scheduleAssignments->first(fn (PersonnelSchedule $assignment) =>
                $assignment->effective_from->lte($date)
                && (! $assignment->effective_to || $assignment->effective_to->gte($date))
            );
            $schedule = $assignment?->schedule;
            $dayField = strtolower($date->format('l'));
            $dateEvents = $holidays->get($date->toDateString(), collect());
            $holiday = $this->applicableHoliday($dateEvents, $person);
            $isSpecialWorkingDay = $dateEvents->contains(fn (Holiday $event) =>
                $event->holiday_type === 'Special Working Holiday'
                && (! $event->department_id || $event->department_id === $person->department_id)
            );
            $isDutyDay = ((bool) ($schedule?->{$dayField}) || $isSpecialWorkingDay) && ! $holiday;
            $record = $records->get($date->toDateString());

            if (! $isDutyDay && ! $record && ! $holiday) {
                continue;
            }

            $status = $record
                ? $this->displayStatus($record, $schedule, $date->copy()->endOfDay())
                : ($holiday ? 'Holiday' : 'Missing');

            $daily->push([
                'date' => $date->toDateString(),
                'day' => $date->format('D'),
                'day_number' => $date->day,
                'is_duty_day' => $isDutyDay,
                'holiday' => $holiday?->holiday_name,
                'status' => $status,
                'morning_time_in' => $record?->morning_time_in?->toISOString(),
                'morning_time_out' => $record?->morning_time_out?->toISOString(),
                'afternoon_time_in' => $record?->afternoon_time_in?->toISOString(),
                'afternoon_time_out' => $record?->afternoon_time_out?->toISOString(),
                'work_minutes' => $record?->total_work_minutes ?? 0,
                'late_minutes' => $record?->late_minutes ?? 0,
                'undertime_minutes' => $record?->undertime_minutes ?? 0,
                'is_verified' => (bool) $record?->is_verified,
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
        return $holidays->first(fn (Holiday $holiday) =>
            $holiday->holiday_type !== 'Special Working Holiday'
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
        return [
            'id' => $certification?->dtr_certification_id,
            'status' => $certification?->certification_status ?? 'Draft',
            'remarks' => $certification?->remarks,
            'prepared_at' => $certification?->prepared_at?->toISOString(),
            'certified_at' => $certification?->certified_at?->toISOString(),
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
}
