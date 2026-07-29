<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AttendanceChangeLog;
use App\Models\DtrStatusLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ActivityLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'source' => ['nullable', Rule::in(['System', 'Attendance', 'DTR'])],
            'user_id' => ['nullable', 'integer', 'exists:system_users,user_id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:10,100'],
        ]);
        $activityQuery = DB::table('activity_logs as activity')
            ->leftJoin('system_users as users', 'users.user_id', '=', 'activity.user_id')
            ->leftJoin('personnel as personnel', 'personnel.personnel_id', '=', 'users.personnel_id')
            ->selectRaw("
                CONCAT('activity-', activity.activity_log_id) as log_key,
                'System' as source,
                activity.user_id as user_id,
                users.username as username,
                CONCAT_WS(' ', personnel.first_name, personnel.middle_name, personnel.last_name, personnel.suffix) as full_name,
                activity.activity_type as action,
                activity.description as description,
                activity.entity_type as entity_type,
                activity.entity_id as entity_id,
                activity.ip_address as ip_address,
                activity.user_agent as user_agent,
                NULL as old_values,
                NULL as new_values,
                NULL as reason,
                activity.request_id as request_id,
                activity.created_at as created_at
            ");
        $attendanceQuery = DB::table('attendance_change_logs as changes')
            ->join('attendance_records as attendance', 'attendance.attendance_id', '=', 'changes.attendance_id')
            ->leftJoin('personnel as attendance_personnel', 'attendance_personnel.personnel_id', '=', 'attendance.personnel_id')
            ->leftJoin('system_users as users', 'users.user_id', '=', 'changes.changed_by')
            ->leftJoin('personnel as user_personnel', 'user_personnel.personnel_id', '=', 'users.personnel_id')
            ->selectRaw("
                CONCAT('attendance-', changes.attendance_change_log_id) as log_key,
                'Attendance' as source,
                changes.changed_by as user_id,
                users.username as username,
                CONCAT_WS(' ', user_personnel.first_name, user_personnel.middle_name, user_personnel.last_name, user_personnel.suffix) as full_name,
                changes.action_type as action,
                CONCAT(
                    'Attendance ', LOWER(changes.action_type), ' for ',
                    CONCAT_WS(' ', attendance_personnel.first_name, attendance_personnel.middle_name, attendance_personnel.last_name, attendance_personnel.suffix),
                    ' on ', DATE_FORMAT(attendance.attendance_date, '%M %e, %Y'), '.'
                ) as description,
                'attendance_records' as entity_type,
                changes.attendance_id as entity_id,
                NULL as ip_address,
                NULL as user_agent,
                changes.old_values as old_values,
                changes.new_values as new_values,
                changes.reason as reason,
                NULL as request_id,
                changes.created_at as created_at
            ");
        $dtrQuery = DB::table('dtr_status_logs as dtr_changes')
            ->join(
                'dtr_certifications as certifications',
                'certifications.dtr_certification_id',
                '=',
                'dtr_changes.dtr_certification_id'
            )
            ->leftJoin('personnel as dtr_personnel', 'dtr_personnel.personnel_id', '=', 'certifications.personnel_id')
            ->leftJoin('system_users as users', 'users.user_id', '=', 'dtr_changes.changed_by')
            ->leftJoin('personnel as user_personnel', 'user_personnel.personnel_id', '=', 'users.personnel_id')
            ->selectRaw("
                CONCAT('dtr-', dtr_changes.dtr_status_log_id) as log_key,
                'DTR' as source,
                dtr_changes.changed_by as user_id,
                users.username as username,
                CONCAT_WS(' ', user_personnel.first_name, user_personnel.middle_name, user_personnel.last_name, user_personnel.suffix) as full_name,
                CONCAT('DTR_', UPPER(dtr_changes.to_status)) as action,
                CONCAT(
                    'DTR for ',
                    CONCAT_WS(' ', dtr_personnel.first_name, dtr_personnel.middle_name, dtr_personnel.last_name, dtr_personnel.suffix),
                    ' changed from ', dtr_changes.from_status, ' to ', dtr_changes.to_status, '.'
                ) as description,
                'dtr_certifications' as entity_type,
                dtr_changes.dtr_certification_id as entity_id,
                dtr_changes.ip_address as ip_address,
                dtr_changes.user_agent as user_agent,
                JSON_OBJECT('status', dtr_changes.from_status) as old_values,
                JSON_OBJECT('status', dtr_changes.to_status) as new_values,
                dtr_changes.remarks as reason,
                dtr_changes.request_id as request_id,
                dtr_changes.created_at as created_at
            ");
        $auditQuery = $activityQuery
            ->unionAll($attendanceQuery)
            ->unionAll($dtrQuery);
        $logs = DB::query()->fromSub($auditQuery, 'audit_logs')
            ->when($validated['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('description', 'like', "%{$search}%")
                        ->orWhere('action', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%")
                        ->orWhere('full_name', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%");
                });
            })
            ->when(
                $validated['source'] ?? null,
                fn ($query, string $source) => $query->where('source', $source)
            )
            ->when(
                $validated['user_id'] ?? null,
                fn ($query, int $userId) => $query->where('user_id', $userId)
            )
            ->when(
                $validated['date_from'] ?? null,
                fn ($query, string $date) => $query->whereDate('created_at', '>=', $date)
            )
            ->when(
                $validated['date_to'] ?? null,
                fn ($query, string $date) => $query->whereDate('created_at', '<=', $date)
            )
            ->orderByDesc('created_at')
            ->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'data' => collect($logs->items())->map(fn ($log) => $this->formatLog($log)),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'from' => $logs->firstItem(),
                'to' => $logs->lastItem(),
            ],
            'summary' => [
                'total' => ActivityLog::query()->count()
                    + AttendanceChangeLog::query()->count()
                    + DtrStatusLog::query()->count(),
                'today' => ActivityLog::query()->whereDate('created_at', today())->count()
                    + AttendanceChangeLog::query()->whereDate('created_at', today())->count()
                    + DtrStatusLog::query()->whereDate('created_at', today())->count(),
                'system' => ActivityLog::query()->count(),
                'attendance' => AttendanceChangeLog::query()->count(),
                'dtr' => DtrStatusLog::query()->count(),
            ],
            'users' => User::query()
                ->with('personnel:personnel_id,first_name,middle_name,last_name,suffix')
                ->orderBy('username')
                ->get()
                ->map(fn (User $user) => [
                    'user_id' => $user->user_id,
                    'username' => $user->username,
                    'full_name' => $user->personnel?->full_name,
                ]),
        ]);
    }

    private function formatLog(object $log): array
    {
        return [
            'log_key' => $log->log_key,
            'source' => $log->source,
            'user_id' => $log->user_id,
            'username' => $log->username,
            'full_name' => trim((string) $log->full_name) ?: null,
            'action' => $log->action,
            'description' => $log->description,
            'entity_type' => $log->entity_type,
            'entity_id' => $log->entity_id,
            'ip_address' => $log->ip_address,
            'user_agent' => $log->user_agent,
            'old_values' => $this->decodeValues($log->old_values),
            'new_values' => $this->decodeValues($log->new_values),
            'reason' => $log->reason,
            'request_id' => $log->request_id,
            'created_at' => $log->created_at,
        ];
    }

    private function decodeValues(?string $values): ?array
    {
        if (! $values) {
            return null;
        }

        $decoded = json_decode($values, true);

        return is_array($decoded) ? $decoded : null;
    }
}
