<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Personnel;
use App\Models\PersonnelActivationLog;
use App\Services\PersonnelOnboardingService;
use App\Support\ClientIp;
use App\Support\RequestId;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PersonnelOnboardingController extends Controller
{
    private const STATES = ['Draft', 'Setup Required', 'Ready', 'Active', 'Inactive'];

    public function index(
        Request $request,
        PersonnelOnboardingService $onboarding
    ): JsonResponse {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', Rule::in(self::STATES)],
            'department_id' => ['nullable', 'integer', 'exists:departments,department_id'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:10,100'],
        ]);
        $today = today()->toDateString();
        $baseQuery = Personnel::query()
            ->when($validated['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('employee_number', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('middle_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when(
                $validated['department_id'] ?? null,
                fn (Builder $query, int $departmentId) => $query->where('department_id', $departmentId)
            );
        $summary = collect(self::STATES)->mapWithKeys(fn (string $state) => [
            Str::snake($state) => $onboarding->applyStateScope(
                clone $baseQuery,
                $state,
                $today
            )->count(),
        ]);
        $query = clone $baseQuery;

        if ($state = ($validated['state'] ?? null)) {
            $onboarding->applyStateScope($query, $state, $today);
        }

        $paginator = $query
            ->with([
                'department',
                'user:user_id,personnel_id,username,user_role,status',
                'scheduleAssignments' => fn ($query) => $query
                    ->with('schedule')
                    ->whereDate('effective_from', '<=', $today)
                    ->where(fn ($query) => $query
                        ->whereNull('effective_to')
                        ->orWhereDate('effective_to', '>=', $today))
                    ->orderByDesc('effective_from'),
            ])
            ->orderByRaw("CASE WHEN status = 'Active' THEN 0 WHEN status = 'Inactive' THEN 1 ELSE 2 END")
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(
                $validated['per_page'] ?? 25,
                ['*'],
                'page',
                $validated['page'] ?? 1
            );

        return response()->json([
            'data' => collect($paginator->items())->map(
                fn (Personnel $personnel) => $this->formatPersonnel(
                    $personnel,
                    $onboarding->evaluate($personnel, $today)
                )
            ),
            'summary' => [
                'total' => (clone $baseQuery)->count(),
                ...$summary->all(),
            ],
            'options' => [
                'states' => self::STATES,
                'departments' => Department::query()
                    ->where('status', 'Active')
                    ->orderBy('department_name')
                    ->get(['department_id', 'department_code', 'department_name']),
            ],
            'meta' => ['pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ]],
        ]);
    }

    public function update(
        Request $request,
        Personnel $personnel,
        PersonnelOnboardingService $onboarding
    ): JsonResponse {
        Gate::authorize('manageOnboarding', $personnel);
        $validated = $request->validate([
            'target_status' => ['required', Rule::in(['Active', 'Inactive'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $this->changeStatuses(
            $request,
            collect([$personnel->personnel_id]),
            $validated['target_status'],
            $validated['reason'] ?? null,
            $onboarding
        )->first();

        return response()->json([
            'message' => $validated['target_status'] === 'Active'
                ? 'Personnel activated successfully.'
                : 'Personnel deactivated successfully.',
            'data' => $result,
        ]);
    }

    public function updateBulk(
        Request $request,
        PersonnelOnboardingService $onboarding
    ): JsonResponse {
        $validated = $request->validate([
            'personnel_ids' => ['required', 'array', 'min:1', 'max:100'],
            'personnel_ids.*' => ['required', 'integer', 'distinct', 'exists:personnel,personnel_id'],
            'target_status' => ['required', Rule::in(['Active', 'Inactive'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $records = Personnel::query()
            ->whereIn('personnel_id', $validated['personnel_ids'])
            ->get();

        foreach ($records as $personnel) {
            Gate::authorize('manageOnboarding', $personnel);
        }

        $changed = $this->changeStatuses(
            $request,
            collect($validated['personnel_ids']),
            $validated['target_status'],
            $validated['reason'] ?? null,
            $onboarding
        );

        return response()->json([
            'message' => $changed->count().' personnel '
                .($validated['target_status'] === 'Active' ? 'activated' : 'deactivated')
                .' successfully.',
            'data' => $changed,
        ]);
    }

    private function changeStatuses(
        Request $request,
        $personnelIds,
        string $targetStatus,
        ?string $reason,
        PersonnelOnboardingService $onboarding
    ) {
        return DB::transaction(function () use (
            $request,
            $personnelIds,
            $targetStatus,
            $reason,
            $onboarding
        ) {
            $records = Personnel::query()
                ->whereIn('personnel_id', $personnelIds)
                ->lockForUpdate()
                ->get()
                ->load(['department', 'user', 'scheduleAssignments.schedule']);

            if ($records->count() !== $personnelIds->count()) {
                throw ValidationException::withMessages([
                    'personnel_ids' => ['One or more personnel records no longer exist.'],
                ]);
            }

            foreach ($records as $personnel) {
                if (
                    $request->user()->user_role !== 'Administrator'
                    && $personnel->user?->user_role === 'Administrator'
                ) {
                    abort(403, "Only an administrator may change another administrator's personnel activation.");
                }

                $readiness = $onboarding->evaluate($personnel);

                if ($targetStatus === 'Active' && ! $readiness['is_ready']) {
                    $missing = collect($readiness['requirements'])
                        ->where('complete', false)
                        ->pluck('label')
                        ->implode(', ');

                    throw ValidationException::withMessages([
                        'personnel_ids' => [
                            "{$personnel->full_name} cannot be activated. Missing: {$missing}.",
                        ],
                    ]);
                }
            }

            return $records->map(function (Personnel $personnel) use (
                $request,
                $targetStatus,
                $reason,
                $onboarding
            ): array {
                $fromStatus = $personnel->status;
                $snapshot = $onboarding->evaluate($personnel);

                if ($fromStatus !== $targetStatus) {
                    $personnel->forceFill(['status' => $targetStatus])->save();

                    PersonnelActivationLog::create([
                        'personnel_id' => $personnel->personnel_id,
                        'changed_by' => $request->user()->user_id,
                        'from_status' => $fromStatus,
                        'to_status' => $targetStatus,
                        'reason' => filled($reason) ? trim($reason) : null,
                        'readiness_snapshot' => $snapshot,
                        'ip_address' => ClientIp::for($request),
                        'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                        'request_id' => RequestId::for($request),
                    ]);
                }

                $personnel->load(['department', 'user', 'scheduleAssignments.schedule']);

                return $this->formatPersonnel($personnel, $onboarding->evaluate($personnel));
            });
        });
    }

    private function formatPersonnel(Personnel $personnel, array $readiness): array
    {
        $referenceDate = Carbon::parse($readiness['reference_date']);
        $currentSchedule = $personnel->scheduleAssignments->first(
            fn ($assignment) => $assignment->effective_from->lte($referenceDate)
                && (! $assignment->effective_to || $assignment->effective_to->gte($referenceDate))
                && $assignment->schedule?->status === 'Active'
        );

        return [
            'personnel_id' => $personnel->personnel_id,
            'employee_number' => $personnel->employee_number,
            'full_name' => $personnel->full_name,
            'personnel_type' => $personnel->personnel_type,
            'position_title' => $personnel->position_title,
            'status' => $personnel->status,
            'department' => $personnel->department ? [
                'department_id' => $personnel->department->department_id,
                'code' => $personnel->department->department_code,
                'name' => $personnel->department->department_name,
            ] : null,
            'system_user' => $personnel->user ? [
                'user_id' => $personnel->user->user_id,
                'username' => $personnel->user->username,
                'role' => $personnel->user->user_role,
                'status' => $personnel->user->status,
            ] : null,
            'schedule' => $currentSchedule ? [
                'schedule_id' => $currentSchedule->schedule_id,
                'name' => $currentSchedule->schedule?->schedule_name,
                'effective_from' => $currentSchedule->effective_from?->toDateString(),
                'effective_to' => $currentSchedule->effective_to?->toDateString(),
            ] : null,
            'employment_start_date' => $personnel->employment_start_date?->toDateString(),
            'employment_end_date' => $personnel->employment_end_date?->toDateString(),
            'qr_valid_until' => $personnel->qr_valid_until?->toDateString(),
            'readiness' => $readiness,
        ];
    }
}
