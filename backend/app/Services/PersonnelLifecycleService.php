<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Personnel;
use App\Models\PersonnelActivationLog;
use App\Models\PersonnelSchedule;
use App\Models\User;
use App\Models\UserNotification;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PersonnelLifecycleService
{
    public function __construct(private readonly SessionRevoker $sessionRevoker) {}

    /**
     * Apply employment lifecycle rules for a single business date.
     *
     * Employment remains valid through employment_end_date. Offboarding is
     * therefore performed only when the end date is earlier than $asOf.
     */
    public function process(?CarbonInterface $asOf = null): array
    {
        $date = ($asOf ? Carbon::instance($asOf) : today())->startOfDay();
        $recipients = $this->lifecycleRecipients();
        $summary = [
            'as_of' => $date->toDateString(),
            'completed' => 0,
            'accounts_deactivated' => 0,
            'sessions_revoked' => 0,
            'current_schedules_capped' => 0,
            'future_schedules_removed' => 0,
            'reminders_synced' => 0,
        ];

        Personnel::query()
            ->where('status', 'Active')
            ->whereNotNull('employment_end_date')
            ->whereDate('employment_end_date', '<', $date->toDateString())
            ->orderBy('personnel_id')
            ->chunkById(100, function (Collection $personnel) use (&$summary, $date, $recipients): void {
                foreach ($personnel as $record) {
                    $result = $this->offboard((int) $record->personnel_id, $date, $recipients);

                    foreach ($result as $key => $value) {
                        $summary[$key] += $value;
                    }
                }
            }, 'personnel_id');

        $summary['reminders_synced'] = $this->syncUpcomingReminders($date, $recipients);

        return $summary;
    }

    private function offboard(int $personnelId, CarbonInterface $asOf, Collection $recipients): array
    {
        $result = [
            'completed' => 0,
            'accounts_deactivated' => 0,
            'sessions_revoked' => 0,
            'current_schedules_capped' => 0,
            'future_schedules_removed' => 0,
        ];
        $offboarded = null;

        DB::transaction(function () use ($personnelId, $asOf, &$result, &$offboarded): void {
            $personnel = Personnel::query()
                ->whereKey($personnelId)
                ->lockForUpdate()
                ->first();

            if (
                ! $personnel
                || $personnel->status !== 'Active'
                || ! $personnel->employment_end_date
                || $personnel->employment_end_date->gte($asOf)
            ) {
                return;
            }

            $endDate = $personnel->employment_end_date->toDateString();
            $futureAssignmentIds = PersonnelSchedule::query()
                ->where('personnel_id', $personnel->personnel_id)
                ->whereDate('effective_from', '>', $endDate)
                ->pluck('personnel_schedule_id');
            $result['future_schedules_removed'] = PersonnelSchedule::query()
                ->whereIn('personnel_schedule_id', $futureAssignmentIds)
                ->delete();
            $result['current_schedules_capped'] = PersonnelSchedule::query()
                ->where('personnel_id', $personnel->personnel_id)
                ->whereDate('effective_from', '<=', $endDate)
                ->where(function ($query) use ($endDate): void {
                    $query->whereNull('effective_to')
                        ->orWhereDate('effective_to', '>', $endDate);
                })
                ->update(['effective_to' => $endDate]);

            $linkedUser = $personnel->user()->lockForUpdate()->first();
            $accountFrom = $linkedUser?->status;

            if ($linkedUser) {
                if ($linkedUser->status !== 'Inactive') {
                    $linkedUser->forceFill([
                        'status' => 'Inactive',
                        'failed_login_attempts' => 0,
                        'locked_until' => null,
                    ])->save();
                    $result['accounts_deactivated'] = 1;
                }

                $this->sessionRevoker->revokeFor($linkedUser);
                $result['sessions_revoked'] = 1;
            }

            $personnel->forceFill(['status' => 'Completed'])->save();
            $result['completed'] = 1;
            $requestId = (string) Str::uuid();
            $snapshot = [
                'source' => 'automatic_employment_offboarding',
                'as_of' => $asOf->toDateString(),
                'employment_end_date' => $endDate,
                'system_user' => $linkedUser ? [
                    'user_id' => $linkedUser->user_id,
                    'from_status' => $accountFrom,
                    'to_status' => $linkedUser->status,
                ] : null,
                'schedule_changes' => [
                    'capped' => $result['current_schedules_capped'],
                    'future_removed' => $result['future_schedules_removed'],
                    'future_assignment_ids' => $futureAssignmentIds->values()->all(),
                ],
            ];

            PersonnelActivationLog::create([
                'personnel_id' => $personnel->personnel_id,
                'changed_by' => null,
                'from_status' => 'Active',
                'to_status' => 'Completed',
                'reason' => "Employment period ended on {$endDate}; automatic offboarding completed.",
                'readiness_snapshot' => $snapshot,
                'ip_address' => null,
                'user_agent' => 'scheduled:lifecycle',
                'request_id' => $requestId,
            ]);
            ActivityLog::create([
                'user_id' => null,
                'activity_type' => 'PERSONNEL_AUTO_OFFBOARDED',
                'description' => "{$personnel->full_name} was automatically marked Completed after the employment end date.",
                'entity_type' => 'personnel',
                'entity_id' => $personnel->personnel_id,
                'ip_address' => null,
                'user_agent' => 'scheduled:lifecycle',
                'request_id' => $requestId,
            ]);

            $offboarded = $personnel->fresh('department');
        }, 3);

        if ($offboarded) {
            $this->notifyOffboarded($offboarded, $recipients);
        }

        return $result;
    }

    private function syncUpcomingReminders(CarbonInterface $asOf, Collection $recipients): int
    {
        $synced = 0;
        $reminderDays = max(1, min((int) config('attendance.employment_reminder_days', 30), 365));

        UserNotification::query()
            ->where('notification_type', 'PersonnelLifecycle')
            ->where('notification_key', 'like', 'lifecycle:employment-ending:%')
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now()]);

        Personnel::query()
            ->with('department:department_id,department_code')
            ->where('status', 'Active')
            ->whereNotNull('employment_end_date')
            ->whereBetween('employment_end_date', [
                $asOf->toDateString(),
                $asOf->copy()->addDays($reminderDays)->toDateString(),
            ])
            ->orderBy('employment_end_date')
            ->orderBy('personnel_id')
            ->chunkById(100, function (Collection $personnel) use ($asOf, $recipients, &$synced): void {
                foreach ($personnel as $record) {
                    $days = $asOf->diffInDays($record->employment_end_date, false);
                    $message = $days === 0
                        ? "{$record->full_name}'s employment ends today. Review continuity or allow automatic offboarding tomorrow."
                        : "{$record->full_name}'s employment ends in {$days} days on {$record->employment_end_date->format('M j, Y')}.";

                    foreach ($this->recipientsFor($recipients, $record->department_id) as $recipient) {
                        $this->upsertNotification(
                            $recipient,
                            "lifecycle:employment-ending:{$record->personnel_id}:{$record->employment_end_date->toDateString()}",
                            'Employment ending soon',
                            $message,
                            $days <= 7 ? 'Warning' : 'Info',
                            '/personnel',
                            ['personnel_id' => $record->personnel_id, 'days_remaining' => $days]
                        );
                        $synced++;
                    }
                }
            }, 'personnel_id');

        return $synced;
    }

    private function notifyOffboarded(Personnel $personnel, Collection $recipients): void
    {
        foreach ($this->recipientsFor($recipients, $personnel->department_id) as $recipient) {
            $this->upsertNotification(
                $recipient,
                "event:personnel-offboarded:{$personnel->personnel_id}:{$personnel->employment_end_date->toDateString()}",
                'Personnel automatically offboarded',
                "{$personnel->full_name} was marked Completed and the linked account was disabled.",
                'Warning',
                '/personnel',
                ['personnel_id' => $personnel->personnel_id]
            );
        }
    }

    private function lifecycleRecipients(): Collection
    {
        return User::query()
            ->with('personnel:personnel_id,department_id')
            ->where('status', 'Active')
            ->whereIn('user_role', ['Administrator', 'HR', 'Supervisor'])
            ->get(['user_id', 'personnel_id', 'user_role']);
    }

    private function recipientsFor(Collection $recipients, ?int $departmentId): Collection
    {
        return $recipients->filter(fn (User $user): bool => in_array($user->user_role, ['Administrator', 'HR'], true)
            || ($user->user_role === 'Supervisor'
                && $departmentId !== null
                && (int) $user->personnel?->department_id === (int) $departmentId));
    }

    private function upsertNotification(
        User $user,
        string $key,
        string $title,
        string $message,
        string $severity,
        string $actionUrl,
        array $metadata
    ): void {
        $contentHash = hash('sha256', json_encode([
            $title,
            $message,
            $severity,
            $actionUrl,
            $metadata,
        ], JSON_THROW_ON_ERROR));

        UserNotification::query()->updateOrCreate(
            ['user_id' => $user->user_id, 'notification_key' => $key],
            [
                'notification_type' => 'PersonnelLifecycle',
                'title' => $title,
                'message' => $message,
                'severity' => $severity,
                'action_url' => $actionUrl,
                'content_hash' => $contentHash,
                'metadata' => $metadata,
                'resolved_at' => null,
            ]
        );
    }
}
