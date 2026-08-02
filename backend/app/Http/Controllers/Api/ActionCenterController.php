<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ActionCenterIndexRequest;
use App\Models\AttendanceCorrectionRequest;
use App\Models\AttendanceRecord;
use App\Models\DtrCertification;
use App\Models\LeaveRecord;
use App\Models\Personnel;
use App\Models\User;
use App\Support\PersonnelAccess;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

final class ActionCenterController extends Controller
{
    private const QUEUES = [
        'attendance_verification' => [
            'label' => 'Attendance verification',
            'description' => 'Complete attendance records waiting for an authorized reviewer.',
            'roles' => ['Administrator', 'HR', 'Supervisor'],
        ],
        'missing_time_outs' => [
            'label' => 'Missing time-outs',
            'description' => 'Time-in entries whose matching time-out window has already closed.',
            'roles' => ['Administrator', 'HR', 'Supervisor'],
        ],
        'correction_requests' => [
            'label' => 'Correction requests',
            'description' => 'Personnel explanations and proposed corrections awaiting HR review.',
            'roles' => ['Administrator', 'HR'],
        ],
        'leave_requests' => [
            'label' => 'Leave requests',
            'description' => 'Leave and official-business requests waiting for approval.',
            'roles' => ['Administrator', 'HR', 'Supervisor'],
        ],
        'returned_dtrs' => [
            'label' => 'Returned DTRs',
            'description' => 'Returned monthly DTRs that require personnel action and resubmission.',
            'roles' => ['Administrator', 'HR', 'Supervisor'],
        ],
        'expiring_qr_cards' => [
            'label' => 'Expiring QR cards',
            'description' => 'Active personnel cards expiring within the next 30 days.',
            'roles' => ['Administrator', 'HR'],
        ],
        'workforce_gaps' => [
            'label' => 'Workforce setup gaps',
            'description' => 'Active personnel without an office or a current active schedule.',
            'roles' => ['Administrator', 'HR'],
        ],
    ];

    public function index(ActionCenterIndexRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();
        $availableQueues = collect(self::QUEUES)
            ->filter(fn (array $definition): bool => in_array(
                $user->user_role,
                $definition['roles'],
                true
            ));
        $queue = $validated['queue'] ?? $availableQueues->keys()->first();

        if (! $queue || ! $availableQueues->has($queue)) {
            return response()->json([
                'message' => 'This Action Center queue is outside your role permissions.',
            ], 403);
        }

        $search = trim((string) ($validated['search'] ?? '')) ?: null;
        $summary = $availableQueues->map(function (array $definition, string $key) use ($user): array {
            return [
                'key' => $key,
                'label' => $definition['label'],
                'description' => $definition['description'],
                'count' => $this->queryFor($key, $user)->count(),
            ];
        })->values();
        $paginator = $this->queryFor($queue, $user, $search)
            ->paginate(
                $validated['per_page'] ?? 15,
                ['*'],
                'page',
                $validated['page'] ?? 1
            );

        return response()->json([
            'server_time' => now()->toISOString(),
            'timezone' => config('app.timezone'),
            'scope' => $this->scopeLabel($user),
            'queue' => $queue,
            'summary' => $summary,
            'data' => collect($paginator->items())
                ->map(fn ($item): array => $this->formatItem($queue, $item)),
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

    private function queryFor(string $queue, User $user, ?string $search = null): Builder
    {
        return match ($queue) {
            'attendance_verification' => $this->attendanceVerificationQuery($user, $search),
            'missing_time_outs' => $this->missingTimeOutQuery($user, $search),
            'correction_requests' => $this->correctionRequestQuery($user, $search),
            'leave_requests' => $this->leaveRequestQuery($user, $search),
            'returned_dtrs' => $this->returnedDtrQuery($user, $search),
            'expiring_qr_cards' => $this->expiringQrQuery($user, $search),
            'workforce_gaps' => $this->workforceGapQuery($user, $search),
        };
    }

    private function attendanceVerificationQuery(User $user, ?string $search): Builder
    {
        return AttendanceRecord::query()
            ->with('personnel.department:department_id,department_code,department_name')
            ->where('is_verified', false)
            ->where('attendance_date', '<=', today()->toDateString())
            ->where('attendance_status', '!=', 'Incomplete')
            ->whereHas('personnel', fn (Builder $query) => $this->scopePersonnel($query, $user, $search))
            ->orderBy('attendance_date')
            ->orderBy('attendance_id');
    }

    private function missingTimeOutQuery(User $user, ?string $search): Builder
    {
        $today = today()->toDateString();
        $currentTime = now()->format('H:i:s');

        return AttendanceRecord::query()
            ->with([
                'personnel.department:department_id,department_code,department_name',
                'schedule:schedule_id,schedule_name,morning_time_out_end,afternoon_time_out_end',
            ])
            ->where('is_verified', false)
            ->whereHas('personnel', fn (Builder $query) => $this->scopePersonnel($query, $user, $search))
            ->where(function (Builder $query) use ($today, $currentTime): void {
                $query
                    ->where(function (Builder $past) use ($today): void {
                        $past->where('attendance_date', '<', $today)
                            ->where(fn (Builder $missing) => $this->whereMissingTimeOut($missing));
                    })
                    ->orWhere(function (Builder $current) use ($today, $currentTime): void {
                        $current->where('attendance_date', $today)
                            ->where(function (Builder $missing) use ($currentTime): void {
                                $missing
                                    ->where(function (Builder $morning) use ($currentTime): void {
                                        $morning->whereNotNull('morning_time_in')
                                            ->whereNull('morning_time_out')
                                            ->whereHas('schedule', fn (Builder $schedule) => $schedule
                                                ->whereNotNull('morning_time_out_end')
                                                ->where('morning_time_out_end', '<', $currentTime));
                                    })
                                    ->orWhere(function (Builder $afternoon) use ($currentTime): void {
                                        $afternoon->whereNotNull('afternoon_time_in')
                                            ->whereNull('afternoon_time_out')
                                            ->whereHas('schedule', fn (Builder $schedule) => $schedule
                                                ->whereNotNull('afternoon_time_out_end')
                                                ->where('afternoon_time_out_end', '<', $currentTime));
                                    });
                            });
                    });
            })
            ->orderBy('attendance_date')
            ->orderBy('attendance_id');
    }

    private function correctionRequestQuery(User $user, ?string $search): Builder
    {
        return AttendanceCorrectionRequest::query()
            ->with('personnel.department:department_id,department_code,department_name')
            ->where('request_status', 'Pending')
            ->whereHas('personnel', fn (Builder $query) => $this->scopePersonnel($query, $user, $search))
            ->oldest('created_at');
    }

    private function leaveRequestQuery(User $user, ?string $search): Builder
    {
        return LeaveRecord::query()
            ->with('personnel.department:department_id,department_code,department_name')
            ->where('approval_status', 'Pending')
            ->whereHas('personnel', fn (Builder $query) => $this->scopePersonnel($query, $user, $search))
            ->oldest('created_at');
    }

    private function returnedDtrQuery(User $user, ?string $search): Builder
    {
        return DtrCertification::query()
            ->with('personnel.department:department_id,department_code,department_name')
            ->where('certification_status', 'Returned')
            ->whereHas('personnel', fn (Builder $query) => $this->scopePersonnel($query, $user, $search))
            ->oldest('updated_at');
    }

    private function expiringQrQuery(User $user, ?string $search): Builder
    {
        return Personnel::query()
            ->with('department:department_id,department_code,department_name')
            ->where('status', 'Active')
            ->whereBetween('qr_valid_until', [
                today()->toDateString(),
                today()->addDays(30)->toDateString(),
            ])
            ->tap(fn (Builder $query) => PersonnelAccess::scope($query, $user))
            ->tap(fn (Builder $query) => $this->applyPersonnelSearch($query, $search))
            ->orderBy('qr_valid_until')
            ->orderBy('personnel_id');
    }

    private function workforceGapQuery(User $user, ?string $search): Builder
    {
        $today = today()->toDateString();

        return Personnel::query()
            ->with('department:department_id,department_code,department_name')
            ->where('status', 'Active')
            ->tap(fn (Builder $query) => PersonnelAccess::scope($query, $user))
            ->tap(fn (Builder $query) => $this->applyPersonnelSearch($query, $search))
            ->where(function (Builder $query) use ($today): void {
                $query->whereNull('department_id')
                    ->orWhereDoesntHave('scheduleAssignments', function (Builder $assignment) use ($today): void {
                        $assignment->where('effective_from', '<=', $today)
                            ->where(function (Builder $dates) use ($today): void {
                                $dates->whereNull('effective_to')
                                    ->orWhere('effective_to', '>=', $today);
                            })
                            ->whereHas('schedule', fn (Builder $schedule) => $schedule
                                ->where('status', 'Active'));
                    });
            })
            ->orderBy('last_name')
            ->orderBy('first_name');
    }

    private function scopePersonnel(Builder $query, User $user, ?string $search): Builder
    {
        return $query
            ->where('status', 'Active')
            ->tap(fn (Builder $query) => PersonnelAccess::scope($query, $user))
            ->tap(fn (Builder $query) => $this->applyPersonnelSearch($query, $search));
    }

    private function applyPersonnelSearch(Builder $query, ?string $search): Builder
    {
        if (! $search) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($search): void {
            $query->where('employee_number', 'like', "%{$search}%")
                ->orWhere('first_name', 'like', "%{$search}%")
                ->orWhere('middle_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%");
        });
    }

    private function whereMissingTimeOut(Builder $query): Builder
    {
        return $query
            ->where(fn (Builder $morning) => $morning
                ->whereNotNull('morning_time_in')
                ->whereNull('morning_time_out'))
            ->orWhere(fn (Builder $afternoon) => $afternoon
                ->whereNotNull('afternoon_time_in')
                ->whereNull('afternoon_time_out'));
    }

    private function formatItem(string $queue, mixed $item): array
    {
        $personnel = $item instanceof Personnel ? $item : $item->personnel;
        $base = [
            'id' => $item->getKey(),
            'personnel_id' => $personnel?->personnel_id,
            'employee_number' => $personnel?->employee_number,
            'full_name' => $personnel?->full_name ?? 'Unknown personnel',
            'department_code' => $personnel?->department?->department_code ?? 'Unassigned',
        ];

        return match ($queue) {
            'attendance_verification' => [
                ...$base,
                'date' => $item->attendance_date->toDateString(),
                'status' => $item->attendance_status,
                'detail' => 'Recorded through '.$item->record_source,
                'action_label' => 'Review attendance',
                'action_url' => '/attendance?date='.$item->attendance_date->toDateString(),
            ],
            'missing_time_outs' => [
                ...$base,
                'date' => $item->attendance_date->toDateString(),
                'status' => 'Incomplete',
                'detail' => $this->missingTimeOutLabel($item),
                'action_label' => 'Open attendance',
                'action_url' => '/attendance?date='.$item->attendance_date->toDateString(),
            ],
            'correction_requests' => [
                ...$base,
                'date' => $item->attendance_date->toDateString(),
                'status' => $item->request_status,
                'detail' => ($item->missing_field === 'morning_time_out' ? 'Morning' : 'Afternoon')
                    .' time-out correction: '.$item->reason,
                'action_label' => 'Review correction',
                'action_url' => '/attendance?date='.$item->attendance_date->toDateString(),
            ],
            'leave_requests' => [
                ...$base,
                'date' => $item->date_from->toDateString(),
                'status' => $item->approval_status,
                'detail' => $item->leave_type.' · '.$item->date_from->format('M j')
                    .' to '.$item->date_to->format('M j, Y'),
                'action_label' => 'Review request',
                'action_url' => '/leave-requests?status=Pending',
            ],
            'returned_dtrs' => [
                ...$base,
                'date' => Carbon::create($item->dtr_year, $item->dtr_month, 1)->toDateString(),
                'status' => $item->certification_status,
                'detail' => Carbon::create($item->dtr_year, $item->dtr_month, 1)->format('F Y')
                    .($item->remarks ? ' · '.$item->remarks : ''),
                'action_label' => 'Open DTR',
                'action_url' => '/dtr?month='.sprintf('%04d-%02d', $item->dtr_year, $item->dtr_month)
                    .'&status=Returned',
            ],
            'expiring_qr_cards' => [
                ...$base,
                'date' => $item->qr_valid_until->toDateString(),
                'status' => 'Expires in '.today()->diffInDays($item->qr_valid_until).' days',
                'detail' => 'Card validity ends '.$item->qr_valid_until->format('F j, Y'),
                'action_label' => 'Manage card',
                'action_url' => '/qr-attendance?tab=cards',
            ],
            'workforce_gaps' => [
                ...$base,
                'date' => null,
                'status' => 'Setup required',
                'detail' => $this->workforceGapLabel($item),
                'action_label' => $item->department_id ? 'Assign schedule' : 'Assign office',
                'action_url' => $item->department_id ? '/schedules' : '/personnel',
            ],
        };
    }

    private function missingTimeOutLabel(AttendanceRecord $record): string
    {
        $missing = [];

        if ($record->morning_time_in && ! $record->morning_time_out) {
            $missing[] = 'morning time-out';
        }
        if ($record->afternoon_time_in && ! $record->afternoon_time_out) {
            $missing[] = 'afternoon time-out';
        }

        return 'Missing '.implode(' and ', $missing).'.';
    }

    private function workforceGapLabel(Personnel $personnel): string
    {
        if (! $personnel->department_id) {
            return 'No department or office is assigned.';
        }

        return 'No current active work schedule is assigned.';
    }

    private function scopeLabel(User $user): string
    {
        if (PersonnelAccess::hasGlobalAccess($user)) {
            return 'All offices';
        }

        $user->loadMissing('personnel.department');

        return $user->personnel?->department?->department_name ?? 'No assigned office';
    }
}
