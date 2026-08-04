<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CancelLeaveRequest;
use App\Http\Requests\ReviewLeaveRequest;
use App\Http\Requests\StoreLeaveRequest;
use App\Models\LeaveRecord;
use App\Models\LeaveRequestLog;
use App\Models\Personnel;
use App\Models\User;
use App\Services\LeaveAttendanceService;
use App\Services\PersonnelOnboardingService;
use App\Support\PersonnelAccess;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class LeaveRequestController extends Controller
{
    private const TYPES = [
        'Vacation Leave',
        'Sick Leave',
        'Emergency Leave',
        'Maternity Leave',
        'Paternity Leave',
        'Special Leave',
        'Official Business',
        'Other',
    ];

    private const STATUSES = ['Pending', 'Approved', 'Rejected', 'Cancelled'];

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', LeaveRecord::class);
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'type' => ['nullable', Rule::in(self::TYPES)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:10,100'],
        ]);
        $user = $request->user();
        $baseQuery = $this->visibleQuery($user);
        $summary = (clone $baseQuery)
            ->selectRaw('approval_status, COUNT(*) as aggregate')
            ->groupBy('approval_status')
            ->pluck('aggregate', 'approval_status');
        $query = (clone $baseQuery)
            ->with([
                'personnel.department:department_id,department_code,department_name',
                'approver:user_id,username,personnel_id',
                'approver.personnel:personnel_id,first_name,middle_name,last_name,suffix',
                'submitter:user_id,username',
                'canceller:user_id,username',
                'history' => fn ($query) => $query
                    ->with('changedBy:user_id,username')
                    ->limit(8),
            ])
            ->when($validated['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('request_number', 'like', "%{$search}%")
                        ->orWhere('reason', 'like', "%{$search}%")
                        ->orWhereHas('personnel', fn ($personnel) => $personnel
                            ->where('employee_number', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('middle_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%"));
                });
            })
            ->when(
                $validated['status'] ?? null,
                fn ($query, string $status) => $query->where('approval_status', $status)
            )
            ->when(
                $validated['type'] ?? null,
                fn ($query, string $type) => $query->where('leave_type', $type)
            )
            ->when(
                $validated['date_from'] ?? null,
                fn ($query, string $date) => $query->whereDate('date_to', '>=', $date)
            )
            ->when(
                $validated['date_to'] ?? null,
                fn ($query, string $date) => $query->whereDate('date_from', '<=', $date)
            )
            ->latest('created_at');
        $paginator = $query->paginate(
            $validated['per_page'] ?? 25,
            ['*'],
            'page',
            $validated['page'] ?? 1
        );

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (LeaveRecord $leaveRecord) => $this->formatRequest($leaveRecord, $user)),
            'meta' => [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ],
                'can_create' => Gate::allows('create', LeaveRecord::class),
                'can_review' => in_array($user->user_role, ['Administrator', 'HR', 'Supervisor'], true),
                'current_personnel_id' => $user->personnel_id,
            ],
            'summary' => [
                'total' => (int) $summary->sum(),
                'pending' => (int) ($summary['Pending'] ?? 0),
                'approved' => (int) ($summary['Approved'] ?? 0),
                'rejected' => (int) ($summary['Rejected'] ?? 0),
                'cancelled' => (int) ($summary['Cancelled'] ?? 0),
            ],
            'options' => [
                'types' => self::TYPES,
                'statuses' => self::STATUSES,
            ],
        ]);
    }

    public function store(
        StoreLeaveRequest $request,
        LeaveAttendanceService $attendanceService,
        PersonnelOnboardingService $onboarding
    ): JsonResponse {
        $user = $request->user();
        $personnel = Personnel::query()
            ->whereKey($user->personnel_id)
            ->where('status', 'Active')
            ->whereNotNull('department_id')
            ->first();

        if (! $personnel) {
            return response()->json([
                'message' => 'Your account must be linked to active personnel with an assigned office.',
            ], 422);
        }

        if ($reason = $onboarding->operationalBlockReason($personnel)) {
            return response()->json(['message' => $reason], 422);
        }

        $validated = $request->validated();
        $start = Carbon::parse($validated['date_from'])->startOfDay();
        $end = Carbon::parse($validated['date_to'])->startOfDay();
        $eligibleDates = $attendanceService->eligibleDates($personnel, $start, $end);

        if ($eligibleDates->isEmpty()) {
            throw ValidationException::withMessages([
                'date_from' => ['The selected range contains no scheduled duty days.'],
            ]);
        }

        $documentPath = null;

        if ($request->hasFile('supporting_document')) {
            $extension = strtolower($request->file('supporting_document')->extension());
            $documentPath = $request->file('supporting_document')->storeAs(
                'leave-documents/'.$personnel->personnel_id,
                Str::uuid().'.'.$extension,
                'local'
            );
        }

        try {
            $leaveRecord = DB::transaction(function () use (
                $validated,
                $user,
                $personnel,
                $start,
                $end,
                $eligibleDates,
                $documentPath
            ): LeaveRecord {
                $overlap = LeaveRecord::query()
                    ->where('personnel_id', $personnel->personnel_id)
                    ->whereIn('approval_status', ['Pending', 'Approved'])
                    ->whereDate('date_from', '<=', $end->toDateString())
                    ->whereDate('date_to', '>=', $start->toDateString())
                    ->lockForUpdate()
                    ->exists();

                if ($overlap) {
                    throw ValidationException::withMessages([
                        'date_from' => ['An active leave or official-business request already overlaps this range.'],
                    ]);
                }

                $leaveRecord = LeaveRecord::create([
                    'request_number' => $this->requestNumber(),
                    'personnel_id' => $personnel->personnel_id,
                    'submitted_by' => $user->user_id,
                    'leave_type' => $validated['leave_type'],
                    'day_part' => 'Full Day',
                    'date_from' => $start->toDateString(),
                    'date_to' => $end->toDateString(),
                    'total_days' => $eligibleDates->count(),
                    'reason' => $validated['reason'],
                    'supporting_document' => $documentPath,
                    'approval_status' => 'Pending',
                ]);
                LeaveRequestLog::create([
                    'leave_id' => $leaveRecord->leave_id,
                    'from_status' => null,
                    'to_status' => 'Pending',
                    'remarks' => 'Request submitted for review.',
                    'changed_by' => $user->user_id,
                ]);

                return $leaveRecord;
            });
        } catch (Throwable $exception) {
            if ($documentPath) {
                Storage::disk('local')->delete($documentPath);
            }

            throw $exception;
        }

        return response()->json([
            'message' => 'Leave or official-business request submitted successfully.',
            'data' => $this->formatRequest(
                $leaveRecord->load([
                    'personnel.department',
                    'submitter',
                    'history.changedBy',
                ]),
                $user
            ),
        ], 201);
    }

    public function review(
        ReviewLeaveRequest $request,
        LeaveRecord $leaveRecord,
        LeaveAttendanceService $attendanceService
    ): JsonResponse {
        $validated = $request->validated();
        $user = $request->user();
        $leaveRecord = DB::transaction(function () use (
            $leaveRecord,
            $validated,
            $user,
            $attendanceService
        ): LeaveRecord {
            $locked = LeaveRecord::query()
                ->with('personnel')
                ->lockForUpdate()
                ->findOrFail($leaveRecord->leave_id);

            if ($locked->approval_status !== 'Pending') {
                throw ValidationException::withMessages([
                    'decision' => ['This request has already been reviewed.'],
                ]);
            }

            if ($validated['decision'] === 'Approved') {
                $attendanceService->syncApproved($locked, $user);
                $locked->forceFill([
                    'approval_status' => 'Approved',
                    'review_remarks' => $validated['remarks'] ?? null,
                    'approved_by' => $user->user_id,
                    'approved_at' => now(),
                ])->save();
            } else {
                $locked->forceFill([
                    'approval_status' => 'Rejected',
                    'review_remarks' => $validated['remarks'],
                    'approved_by' => $user->user_id,
                    'approved_at' => now(),
                ])->save();
            }

            LeaveRequestLog::create([
                'leave_id' => $locked->leave_id,
                'from_status' => 'Pending',
                'to_status' => $validated['decision'],
                'remarks' => $validated['remarks'] ?? 'Request approved.',
                'changed_by' => $user->user_id,
            ]);

            return $locked;
        });

        return response()->json([
            'message' => "Request {$validated['decision']} successfully.",
            'data' => $this->formatRequest(
                $leaveRecord->fresh([
                    'personnel.department',
                    'approver.personnel',
                    'submitter',
                    'history.changedBy',
                ]),
                $user
            ),
        ]);
    }

    public function cancel(
        CancelLeaveRequest $request,
        LeaveRecord $leaveRecord,
        LeaveAttendanceService $attendanceService
    ): JsonResponse {
        $validated = $request->validated();
        $user = $request->user();
        $leaveRecord = DB::transaction(function () use (
            $leaveRecord,
            $validated,
            $user,
            $attendanceService
        ): LeaveRecord {
            $locked = LeaveRecord::query()
                ->lockForUpdate()
                ->findOrFail($leaveRecord->leave_id);

            if (! in_array($locked->approval_status, ['Pending', 'Approved'], true)) {
                throw ValidationException::withMessages([
                    'reason' => ['Only pending or approved requests may be cancelled.'],
                ]);
            }

            $previousStatus = $locked->approval_status;

            if ($previousStatus === 'Approved') {
                $attendanceService->removeGeneratedAttendance($locked);
            }

            $locked->forceFill([
                'approval_status' => 'Cancelled',
                'cancelled_by' => $user->user_id,
                'cancelled_at' => now(),
                'cancellation_reason' => $validated['reason'],
            ])->save();
            LeaveRequestLog::create([
                'leave_id' => $locked->leave_id,
                'from_status' => $previousStatus,
                'to_status' => 'Cancelled',
                'remarks' => $validated['reason'],
                'changed_by' => $user->user_id,
            ]);

            return $locked;
        });

        return response()->json([
            'message' => 'Request cancelled successfully.',
            'data' => $this->formatRequest(
                $leaveRecord->fresh([
                    'personnel.department',
                    'approver.personnel',
                    'submitter',
                    'canceller',
                    'history.changedBy',
                ]),
                $user
            ),
        ]);
    }

    public function document(
        Request $request,
        LeaveRecord $leaveRecord
    ): StreamedResponse|JsonResponse {
        Gate::authorize('document', $leaveRecord);

        if (! $leaveRecord->supporting_document
            || ! Storage::disk('local')->exists($leaveRecord->supporting_document)) {
            return response()->json([
                'message' => 'The supporting document is unavailable.',
            ], 404);
        }

        $extension = pathinfo($leaveRecord->supporting_document, PATHINFO_EXTENSION);
        $downloadName = ($leaveRecord->request_number ?: "leave-{$leaveRecord->leave_id}")
            .'.'.$extension;

        return Storage::disk('local')->download(
            $leaveRecord->supporting_document,
            $downloadName
        );
    }

    private function visibleQuery(User $user): Builder
    {
        $query = LeaveRecord::query();

        if (in_array($user->user_role, ['Administrator', 'HR'], true)) {
            return $query;
        }

        if ($user->user_role === 'Supervisor') {
            return $query->whereHas(
                'personnel',
                fn ($personnel) => PersonnelAccess::scope($personnel, $user)
            );
        }

        return $query->where('personnel_id', $user->personnel_id ?? -1);
    }

    private function requestNumber(): string
    {
        do {
            $number = 'LR-'.now()->format('Y').'-'.Str::upper(Str::random(10));
        } while (LeaveRecord::query()->where('request_number', $number)->exists());

        return $number;
    }

    private function formatRequest(LeaveRecord $leaveRecord, User $user): array
    {
        $documentAvailable = $leaveRecord->supporting_document
            && Storage::disk('local')->exists($leaveRecord->supporting_document);

        return [
            'leave_id' => $leaveRecord->leave_id,
            'request_number' => $leaveRecord->request_number
                ?: "LEGACY-{$leaveRecord->leave_id}",
            'personnel_id' => $leaveRecord->personnel_id,
            'personnel' => $leaveRecord->personnel ? [
                'employee_number' => $leaveRecord->personnel->employee_number,
                'full_name' => $leaveRecord->personnel->full_name,
                'department' => $leaveRecord->personnel->department ? [
                    'code' => $leaveRecord->personnel->department->department_code,
                    'name' => $leaveRecord->personnel->department->department_name,
                ] : null,
            ] : null,
            'leave_type' => $leaveRecord->leave_type,
            'day_part' => $leaveRecord->day_part ?? 'Full Day',
            'date_from' => $leaveRecord->date_from->toDateString(),
            'date_to' => $leaveRecord->date_to->toDateString(),
            'total_days' => (float) $leaveRecord->total_days,
            'reason' => $leaveRecord->reason,
            'status' => $leaveRecord->approval_status,
            'review_remarks' => $leaveRecord->review_remarks,
            'reviewed_by' => $leaveRecord->approver?->personnel?->full_name
                ?? $leaveRecord->approver?->username,
            'reviewed_at' => $leaveRecord->approved_at?->toISOString(),
            'cancellation_reason' => $leaveRecord->cancellation_reason,
            'cancelled_at' => $leaveRecord->cancelled_at?->toISOString(),
            'has_document' => (bool) $documentAvailable,
            'document_url' => $documentAvailable
                ? "/api/leave-requests/{$leaveRecord->leave_id}/document"
                : null,
            'can_review' => Gate::forUser($user)->allows('review', $leaveRecord),
            'can_cancel' => Gate::forUser($user)->allows('cancel', $leaveRecord),
            'created_at' => $leaveRecord->created_at?->toISOString(),
            'history' => $leaveRecord->relationLoaded('history')
                ? $leaveRecord->history->map(fn (LeaveRequestLog $log) => [
                    'id' => $log->leave_request_log_id,
                    'from_status' => $log->from_status,
                    'to_status' => $log->to_status,
                    'remarks' => $log->remarks,
                    'changed_by' => $log->changedBy?->username,
                    'created_at' => $log->created_at?->toISOString(),
                ])->values()
                : [],
        ];
    }
}
