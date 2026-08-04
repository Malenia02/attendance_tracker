<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Personnel;
use App\Models\PersonnelSchedule;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ScheduleController extends Controller
{
    private const DAYS = [
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
        'sunday',
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['Active', 'Inactive'])],
            'personnel_search' => ['nullable', 'string', 'max:100'],
            'assignment' => ['nullable', Rule::in(['assigned', 'unassigned'])],
            'personnel_page' => ['nullable', 'integer', 'min:1'],
            'personnel_per_page' => ['nullable', 'integer', 'between:10,100'],
        ]);
        $today = now()->toDateString();

        $schedules = WorkSchedule::query()
            ->withCount([
                'personnelSchedules as total_assignments_count',
                'personnelSchedules as active_personnel_count' => fn ($query) => $query
                    ->whereDate('effective_from', '<=', $today)
                    ->where(fn ($query) => $query
                        ->whereNull('effective_to')
                        ->orWhereDate('effective_to', '>=', $today)),
                'attendanceRecords',
            ])
            ->when($validated['search'] ?? null, fn ($query, string $search) => $query
                ->where('schedule_name', 'like', "%{$search}%"))
            ->when(
                $validated['status'] ?? null,
                fn ($query, string $status) => $query->where('status', $status)
            )
            ->orderByDesc('status')
            ->orderBy('schedule_name')
            ->get();

        // Include inactive onboarding records so HR can assign the schedule
        // required before activation. Completed/terminated records stay hidden.
        $activePersonnelQuery = Personnel::query()->whereIn('status', ['Active', 'Inactive']);
        $activePersonnelCount = (clone $activePersonnelQuery)->count();
        $assignedPersonnel = (clone $activePersonnelQuery)
            ->whereHas('scheduleAssignments', fn ($query) => $query
                ->whereDate('effective_from', '<=', $today)
                ->where(fn ($query) => $query
                    ->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $today))
                ->whereHas('schedule', fn ($schedule) => $schedule->where('status', 'Active')))
            ->count();
        $personnelPaginator = (clone $activePersonnelQuery)
            ->with([
                'department:department_id,department_code,department_name',
                'scheduleAssignments' => fn ($query) => $query
                    ->with('schedule')
                    ->orderByDesc('effective_from'),
            ])
            ->when($validated['personnel_search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('employee_number', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('middle_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%");
                });
            })
            ->when($validated['assignment'] ?? null, function ($query, string $assignment) use ($today): void {
                $relation = fn ($assignments) => $assignments
                    ->whereDate('effective_from', '<=', $today)
                    ->where(fn ($assignments) => $assignments
                        ->whereNull('effective_to')
                        ->orWhereDate('effective_to', '>=', $today))
                    ->whereHas('schedule', fn ($schedule) => $schedule->where('status', 'Active'));

                if ($assignment === 'assigned') {
                    $query->whereHas('scheduleAssignments', $relation);
                } else {
                    $query->whereDoesntHave('scheduleAssignments', $relation);
                }
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(
                $validated['personnel_per_page'] ?? 25,
                ['*'],
                'personnel_page',
                $validated['personnel_page'] ?? 1
            );
        $personnel = collect($personnelPaginator->items());

        return response()->json([
            'data' => $schedules->map(fn (WorkSchedule $schedule) => $this->formatSchedule($schedule)),
            'personnel' => $personnel->map(
                fn (Personnel $person) => $this->formatPersonnelAssignment($person, $today)
            ),
            'summary' => [
                'total' => WorkSchedule::count(),
                'active' => WorkSchedule::where('status', 'Active')->count(),
                'assigned_personnel' => $assignedPersonnel,
                'unassigned_personnel' => max(0, $activePersonnelCount - $assignedPersonnel),
            ],
            'meta' => ['personnel_pagination' => [
                'current_page' => $personnelPaginator->currentPage(),
                'per_page' => $personnelPaginator->perPage(),
                'total' => $personnelPaginator->total(),
                'last_page' => $personnelPaginator->lastPage(),
                'from' => $personnelPaginator->firstItem(),
                'to' => $personnelPaginator->lastItem(),
            ]],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $schedule = WorkSchedule::create($this->validatedSchedule($request));

        return response()->json([
            'message' => 'Work schedule created successfully.',
            'data' => $this->formatSchedule(
                $schedule->loadCount([
                    'personnelSchedules as total_assignments_count',
                    'personnelSchedules as active_personnel_count',
                    'attendanceRecords',
                ])
            ),
        ], 201);
    }

    public function update(Request $request, WorkSchedule $schedule): JsonResponse
    {
        $schedule->update($this->validatedSchedule($request, $schedule));

        return response()->json([
            'message' => 'Work schedule updated successfully.',
            'data' => $this->formatSchedule(
                $schedule->loadCount([
                    'personnelSchedules as total_assignments_count',
                    'personnelSchedules as active_personnel_count',
                    'attendanceRecords',
                ])
            ),
        ]);
    }

    public function destroy(WorkSchedule $schedule): JsonResponse
    {
        $schedule->loadCount(['personnelSchedules', 'attendanceRecords']);

        if ($schedule->personnel_schedules_count || $schedule->attendance_records_count) {
            return response()->json([
                'message' => 'This schedule is already in use. Set it to Inactive to preserve attendance history.',
            ], 422);
        }

        $schedule->delete();

        return response()->json([
            'message' => 'Work schedule removed successfully.',
        ]);
    }

    public function assign(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'schedule_id' => [
                'required',
                'integer',
                Rule::exists('work_schedules', 'schedule_id')->where('status', 'Active'),
            ],
            'personnel_ids' => ['required', 'array', 'min:1', 'max:250'],
            'personnel_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('personnel', 'personnel_id')->where(
                    fn ($query) => $query->whereIn('status', ['Active', 'Inactive'])
                ),
            ],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);

        $start = Carbon::parse($validated['effective_from'])->startOfDay();
        $requestedEnd = isset($validated['effective_to'])
            ? Carbon::parse($validated['effective_to'])->startOfDay()
            : null;

        $assignments = DB::transaction(function () use ($validated, $start, $requestedEnd, $request) {
            return collect($validated['personnel_ids'])->map(function (int $personnelId) use (
                $validated,
                $start,
                $requestedEnd,
                $request
            ): PersonnelSchedule {
                $existingAtStart = PersonnelSchedule::query()
                    ->where('personnel_id', $personnelId)
                    ->whereDate('effective_from', $start->toDateString())
                    ->lockForUpdate()
                    ->first();

                PersonnelSchedule::query()
                    ->where('personnel_id', $personnelId)
                    ->whereDate('effective_from', '<', $start->toDateString())
                    ->where(fn ($query) => $query
                        ->whereNull('effective_to')
                        ->orWhereDate('effective_to', '>=', $start->toDateString()))
                    ->lockForUpdate()
                    ->update(['effective_to' => $start->copy()->subDay()->toDateString()]);

                $nextAssignment = PersonnelSchedule::query()
                    ->where('personnel_id', $personnelId)
                    ->whereDate('effective_from', '>', $start->toDateString())
                    ->orderBy('effective_from')
                    ->lockForUpdate()
                    ->first();

                $effectiveEnd = $requestedEnd?->copy();

                if ($nextAssignment) {
                    $dayBeforeNext = $nextAssignment->effective_from->copy()->subDay();

                    if (! $effectiveEnd || $effectiveEnd->greaterThan($dayBeforeNext)) {
                        $effectiveEnd = $dayBeforeNext;
                    }
                }

                $values = [
                    'schedule_id' => $validated['schedule_id'],
                    'effective_to' => $effectiveEnd?->toDateString(),
                    'created_by' => $request->user()->user_id,
                ];

                if ($existingAtStart) {
                    $existingAtStart->update($values);

                    return $existingAtStart->fresh(['schedule', 'personnel']);
                }

                return PersonnelSchedule::create([
                    'personnel_id' => $personnelId,
                    'effective_from' => $start->toDateString(),
                    ...$values,
                ])->load(['schedule', 'personnel']);
            });
        });

        return response()->json([
            'message' => $assignments->count() === 1
                ? 'Personnel schedule assigned successfully.'
                : $assignments->count().' personnel schedules assigned successfully.',
            'data' => $assignments->map(fn (PersonnelSchedule $assignment) => $this->formatAssignment($assignment)),
        ], 201);
    }

    public function destroyAssignment(PersonnelSchedule $personnelSchedule): JsonResponse
    {
        $personnelSchedule->delete();

        return response()->json([
            'message' => 'Personnel schedule assignment removed successfully.',
        ]);
    }

    private function validatedSchedule(Request $request, ?WorkSchedule $schedule = null): array
    {
        $validated = $request->validate([
            'schedule_name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('work_schedules', 'schedule_name')
                    ->ignore($schedule?->schedule_id, 'schedule_id'),
            ],
            'morning_start' => ['required', 'date_format:H:i'],
            'morning_end' => ['required', 'date_format:H:i', 'after:morning_start'],
            'afternoon_start' => ['required', 'date_format:H:i', 'after_or_equal:morning_end'],
            'afternoon_end' => ['required', 'date_format:H:i', 'after:afternoon_start'],
            'morning_time_in_start' => ['required', 'date_format:H:i'],
            'morning_time_in_end' => ['required', 'date_format:H:i', 'after:morning_time_in_start'],
            'morning_time_out_start' => ['required', 'date_format:H:i'],
            'morning_time_out_end' => ['required', 'date_format:H:i', 'after:morning_time_out_start'],
            'afternoon_time_in_start' => ['required', 'date_format:H:i'],
            'afternoon_time_in_end' => ['required', 'date_format:H:i', 'after:afternoon_time_in_start'],
            'afternoon_time_out_start' => ['required', 'date_format:H:i'],
            'afternoon_time_out_end' => ['required', 'date_format:H:i', 'after:afternoon_time_out_start'],
            'grace_period_minutes' => ['required', 'integer', 'between:0,180'],
            'required_minutes_per_day' => ['required', 'integer', 'between:60,1440'],
            ...collect(self::DAYS)->mapWithKeys(fn (string $day) => [$day => ['required', 'boolean']])->all(),
            'status' => ['required', Rule::in(['Active', 'Inactive'])],
        ]);

        if (! collect(self::DAYS)->contains(fn (string $day) => (bool) $validated[$day])) {
            throw ValidationException::withMessages([
                'working_days' => ['Select at least one regular working day.'],
            ]);
        }

        return $validated;
    }

    private function currentAssignment(Personnel $personnel, string $date): ?PersonnelSchedule
    {
        return $personnel->scheduleAssignments->first(
            fn (PersonnelSchedule $assignment) => $assignment->effective_from->toDateString() <= $date
                && (! $assignment->effective_to || $assignment->effective_to->toDateString() >= $date)
                && $assignment->schedule?->status === 'Active'
        );
    }

    private function formatPersonnelAssignment(Personnel $personnel, string $today): array
    {
        $current = $this->currentAssignment($personnel, $today);
        $upcoming = $personnel->scheduleAssignments
            ->filter(fn (PersonnelSchedule $assignment) => $assignment->effective_from->toDateString() > $today
                && $assignment->schedule?->status === 'Active')
            ->sortBy('effective_from')
            ->first();

        return [
            'personnel_id' => $personnel->personnel_id,
            'employee_number' => $personnel->employee_number,
            'full_name' => $personnel->full_name,
            'personnel_type' => $personnel->personnel_type,
            'department' => $personnel->department ? [
                'code' => $personnel->department->department_code,
                'name' => $personnel->department->department_name,
            ] : null,
            'current_assignment' => $current ? $this->formatAssignment($current) : null,
            'upcoming_assignment' => $upcoming ? $this->formatAssignment($upcoming) : null,
        ];
    }

    private function formatAssignment(PersonnelSchedule $assignment): array
    {
        return [
            'personnel_schedule_id' => $assignment->personnel_schedule_id,
            'personnel_id' => $assignment->personnel_id,
            'schedule_id' => $assignment->schedule_id,
            'schedule_name' => $assignment->schedule?->schedule_name,
            'schedule_status' => $assignment->schedule?->status,
            'effective_from' => $assignment->effective_from?->format('Y-m-d'),
            'effective_to' => $assignment->effective_to?->format('Y-m-d'),
        ];
    }

    private function formatSchedule(WorkSchedule $schedule): array
    {
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
            'grace_period_minutes' => $schedule->grace_period_minutes,
            'required_minutes_per_day' => $schedule->required_minutes_per_day,
            'working_days' => collect(self::DAYS)
                ->filter(fn (string $day) => (bool) $schedule->{$day})
                ->values(),
            ...collect(self::DAYS)->mapWithKeys(fn (string $day) => [$day => (bool) $schedule->{$day}])->all(),
            'status' => $schedule->status,
            'active_personnel_count' => (int) ($schedule->active_personnel_count ?? 0),
            'total_assignments_count' => (int) ($schedule->total_assignments_count ?? 0),
            'attendance_records_count' => (int) ($schedule->attendance_records_count ?? 0),
        ];
    }
}
