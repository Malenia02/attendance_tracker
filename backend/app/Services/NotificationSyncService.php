<?php

namespace App\Services;

use App\Models\AttendanceCorrectionRequest;
use App\Models\AttendanceRecord;
use App\Models\DtrCertification;
use App\Models\LeaveRecord;
use App\Models\Personnel;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class NotificationSyncService
{
    private const REVIEW_ROLES = ['Administrator', 'HR', 'Supervisor'];

    public function syncFor(User $user): void
    {
        $definitions = $this->definitionsFor($user);
        $activeStateKeys = collect($definitions)
            ->pluck('key')
            ->filter(fn (string $key): bool => str_starts_with($key, 'state:'))
            ->values()
            ->all();

        DB::transaction(function () use ($user, $definitions, $activeStateKeys): void {
            foreach ($definitions as $definition) {
                $this->persist($user, $definition);
            }

            UserNotification::query()
                ->where('user_id', $user->user_id)
                ->whereNull('resolved_at')
                ->where('notification_key', 'like', 'state:%')
                ->when(
                    $activeStateKeys !== [],
                    fn (Builder $query) => $query->whereNotIn('notification_key', $activeStateKeys)
                )
                ->update(['resolved_at' => now(), 'updated_at' => now()]);
        }, 3);
    }

    private function definitionsFor(User $user): array
    {
        $definitions = [];

        if (in_array($user->user_role, self::REVIEW_ROLES, true)) {
            $this->appendReviewQueues($definitions, $user);
        } else {
            $this->appendPersonalQueues($definitions, $user);
        }

        $this->appendPersonalEvents($definitions, $user);

        if ($user->user_role === 'Administrator') {
            $locked = User::query()
                ->where(function (Builder $query): void {
                    $query->where('status', 'Locked')
                        ->orWhere('locked_until', '>', now());
                })
                ->count();

            $this->appendCount(
                $definitions,
                $locked,
                'state:security:locked-accounts',
                'account_security',
                'Locked accounts need review',
                'There '.($locked === 1 ? 'is 1 locked account' : "are {$locked} locked accounts").' requiring administrator review.',
                'Danger',
                '/system-users'
            );
        }

        return $definitions;
    }

    private function appendReviewQueues(array &$definitions, User $user): void
    {
        $unverified = AttendanceRecord::query()
            ->where('is_verified', false)
            ->where('attendance_status', '!=', 'Incomplete')
            ->whereHas('personnel', fn (Builder $query) => $this->scopePersonnel($query, $user))
            ->count();
        $this->appendCount(
            $definitions,
            $unverified,
            'state:review:attendance',
            'attendance_verification',
            'Attendance waiting for verification',
            "{$unverified} attendance ".($unverified === 1 ? 'record is' : 'records are').' ready for review.',
            'Warning',
            '/action-center?queue=attendance_verification'
        );

        $missing = $this->missingTimeOutQuery($user)->count();
        $this->appendCount(
            $definitions,
            $missing,
            'state:review:missing-time-outs',
            'missing_time_outs',
            'Missing time-outs require action',
            "{$missing} attendance ".($missing === 1 ? 'record has' : 'records have').' a missing time-out.',
            'Danger',
            '/action-center?queue=missing_time_outs'
        );

        $pendingLeave = LeaveRecord::query()
            ->where('approval_status', 'Pending')
            ->whereHas('personnel', fn (Builder $query) => $this->scopePersonnel($query, $user))
            ->count();
        $this->appendCount(
            $definitions,
            $pendingLeave,
            'state:review:leave',
            'leave_requests',
            'Leave requests awaiting review',
            "{$pendingLeave} leave ".($pendingLeave === 1 ? 'request is' : 'requests are').' pending.',
            'Warning',
            '/action-center?queue=leave_requests'
        );

        $returnedDtrs = DtrCertification::query()
            ->where('certification_status', 'Returned')
            ->whereHas('personnel', fn (Builder $query) => $this->scopePersonnel($query, $user))
            ->count();
        $this->appendCount(
            $definitions,
            $returnedDtrs,
            'state:review:returned-dtrs',
            'returned_dtrs',
            'Returned DTRs need follow-up',
            "{$returnedDtrs} returned ".($returnedDtrs === 1 ? 'DTR needs' : 'DTRs need').' personnel action.',
            'Warning',
            '/action-center?queue=returned_dtrs'
        );

        if (! in_array($user->user_role, ['Administrator', 'HR'], true)) {
            return;
        }

        $corrections = AttendanceCorrectionRequest::query()
            ->where('request_status', 'Pending')
            ->count();
        $this->appendCount(
            $definitions,
            $corrections,
            'state:review:corrections',
            'correction_requests',
            'Correction requests awaiting review',
            "{$corrections} attendance correction ".($corrections === 1 ? 'request is' : 'requests are').' pending.',
            'Warning',
            '/action-center?queue=correction_requests'
        );

        $expiring = Personnel::query()
            ->where('status', 'Active')
            ->whereBetween('qr_valid_until', [today(), today()->addDays(30)])
            ->count();
        $this->appendCount(
            $definitions,
            $expiring,
            'state:review:expiring-cards',
            'expiring_qr_cards',
            'QR cards expire soon',
            "{$expiring} personnel ".($expiring === 1 ? 'card expires' : 'cards expire').' within 30 days.',
            'Info',
            '/action-center?queue=expiring_qr_cards'
        );

        $gaps = $this->workforceGapQuery()->count();
        $this->appendCount(
            $definitions,
            $gaps,
            'state:review:workforce-gaps',
            'workforce_gaps',
            'Personnel setup is incomplete',
            "{$gaps} active personnel ".($gaps === 1 ? 'record needs' : 'records need').' an office or active schedule.',
            'Danger',
            '/action-center?queue=workforce_gaps'
        );
    }

    private function appendPersonalQueues(array &$definitions, User $user): void
    {
        if (! $user->personnel_id) {
            return;
        }

        $missing = $this->missingTimeOutQuery($user, true)->count();
        $this->appendCount(
            $definitions,
            $missing,
            'state:personal:missing-time-outs',
            'missing_time_outs',
            'You have a missing time-out',
            "{$missing} of your attendance ".($missing === 1 ? 'records needs' : 'records need').' a correction request.',
            'Danger',
            '/attendance'
        );

        $pendingLeave = LeaveRecord::query()
            ->where('personnel_id', $user->personnel_id)
            ->where('approval_status', 'Pending')
            ->count();
        $this->appendCount(
            $definitions,
            $pendingLeave,
            'state:personal:pending-leave',
            'leave_requests',
            'Your leave request is pending',
            "{$pendingLeave} of your leave ".($pendingLeave === 1 ? 'requests is' : 'requests are').' awaiting review.',
            'Info',
            '/leave-requests?status=Pending'
        );

        $returned = DtrCertification::query()
            ->where('personnel_id', $user->personnel_id)
            ->where('certification_status', 'Returned')
            ->count();
        $this->appendCount(
            $definitions,
            $returned,
            'state:personal:returned-dtr',
            'returned_dtrs',
            'Your DTR was returned',
            "{$returned} monthly ".($returned === 1 ? 'DTR needs' : 'DTRs need').' correction and resubmission.',
            'Warning',
            '/dtr?status=Returned'
        );

        $personnel = Personnel::query()->find($user->personnel_id);
        if (! $personnel) {
            return;
        }

        if ($personnel->qr_valid_until
            && $personnel->qr_valid_until->betweenIncluded(today(), today()->addDays(30))) {
            $days = today()->diffInDays($personnel->qr_valid_until);
            $definitions[] = $this->definition(
                'state:personal:qr-expiry',
                'expiring_qr_cards',
                'Your personnel card expires soon',
                "Your QR card expires in {$days} ".($days === 1 ? 'day' : 'days').'. Contact HR for renewal.',
                'Warning',
                '/qr-attendance?tab=cards'
            );
        }

        $hasSchedule = $personnel->scheduleAssignments()
            ->where('effective_from', '<=', today())
            ->where(fn (Builder $query) => $query
                ->whereNull('effective_to')
                ->orWhere('effective_to', '>=', today()))
            ->whereHas('schedule', fn (Builder $query) => $query->where('status', 'Active'))
            ->exists();

        if (! $personnel->department_id || ! $hasSchedule) {
            $definitions[] = $this->definition(
                'state:personal:workforce-gap',
                'workforce_gaps',
                'Your attendance setup is incomplete',
                ! $personnel->department_id
                    ? 'No office is assigned to your personnel record. Contact HR.'
                    : 'No active work schedule is assigned to your personnel record. Contact HR.',
                'Danger',
                '/dashboard'
            );
        }
    }

    private function appendPersonalEvents(array &$definitions, User $user): void
    {
        if (! $user->personnel_id) {
            return;
        }

        LeaveRecord::query()
            ->where('personnel_id', $user->personnel_id)
            ->whereIn('approval_status', ['Approved', 'Rejected', 'Cancelled'])
            ->where('updated_at', '>=', now()->subDays(30))
            ->latest('updated_at')
            ->limit(10)
            ->get()
            ->each(function (LeaveRecord $leave) use (&$definitions): void {
                $status = $leave->approval_status;
                $definitions[] = $this->definition(
                    "event:leave:{$leave->leave_id}:{$status}",
                    'leave_decision',
                    "Leave request {$status}",
                    "Your {$leave->leave_type} request for {$leave->date_from->format('M j')} to {$leave->date_to->format('M j, Y')} was ".strtolower($status).'.',
                    $status === 'Approved' ? 'Success' : 'Warning',
                    '/leave-requests'
                );
            });
    }

    private function missingTimeOutQuery(User $user, bool $personalOnly = false): Builder
    {
        return AttendanceRecord::query()
            ->where('is_verified', false)
            ->where('attendance_date', '<=', today())
            ->where(function (Builder $query): void {
                $query->where(fn (Builder $morning) => $morning
                    ->whereNotNull('morning_time_in')
                    ->whereNull('morning_time_out'))
                    ->orWhere(fn (Builder $afternoon) => $afternoon
                        ->whereNotNull('afternoon_time_in')
                        ->whereNull('afternoon_time_out'));
            })
            ->whereHas('personnel', function (Builder $query) use ($user, $personalOnly): void {
                $query->where('status', 'Active');
                $personalOnly
                    ? $query->whereKey($user->personnel_id ?? -1)
                    : $this->scopePersonnel($query, $user);
            });
    }

    private function workforceGapQuery(): Builder
    {
        return Personnel::query()
            ->where('status', 'Active')
            ->where(function (Builder $query): void {
                $query->whereNull('department_id')
                    ->orWhereDoesntHave('scheduleAssignments', function (Builder $assignment): void {
                        $assignment->where('effective_from', '<=', today())
                            ->where(fn (Builder $dates) => $dates
                                ->whereNull('effective_to')
                                ->orWhere('effective_to', '>=', today()))
                            ->whereHas('schedule', fn (Builder $schedule) => $schedule
                                ->where('status', 'Active'));
                    });
            });
    }

    private function scopePersonnel(Builder $query, User $user): void
    {
        $query->where('status', 'Active');

        if (in_array($user->user_role, ['Administrator', 'HR'], true)) {
            return;
        }

        $user->loadMissing('personnel');
        $query->where('department_id', $user->personnel?->department_id ?? -1);
    }

    private function appendCount(
        array &$definitions,
        int $count,
        string $key,
        string $type,
        string $title,
        string $message,
        string $severity,
        string $actionUrl
    ): void {
        if ($count > 0) {
            $definitions[] = $this->definition(
                $key,
                $type,
                $title,
                $message,
                $severity,
                $actionUrl,
                ['count' => $count]
            );
        }
    }

    private function definition(
        string $key,
        string $type,
        string $title,
        string $message,
        string $severity,
        string $actionUrl,
        array $metadata = []
    ): array {
        if (! preg_match('#^/[A-Za-z0-9?&=_/.-]*$#', $actionUrl)) {
            throw new \InvalidArgumentException('Notification action URLs must be internal paths.');
        }

        return compact(
            'key',
            'type',
            'title',
            'message',
            'severity',
            'actionUrl',
            'metadata'
        );
    }

    private function persist(User $user, array $definition): void
    {
        $contentHash = hash('sha256', json_encode([
            $definition['type'],
            $definition['title'],
            $definition['message'],
            $definition['severity'],
            $definition['actionUrl'],
            $definition['metadata'],
        ], JSON_THROW_ON_ERROR));
        $identity = [
            'user_id' => $user->user_id,
            'notification_key' => $definition['key'],
        ];
        $notification = UserNotification::query()
            ->where($identity)
            ->lockForUpdate()
            ->first();

        if (! $notification) {
            $inserted = UserNotification::query()->insertOrIgnore([[
                ...$identity,
                'notification_type' => $definition['type'],
                'title' => $definition['title'],
                'message' => $definition['message'],
                'severity' => $definition['severity'],
                'action_url' => $definition['actionUrl'],
                'content_hash' => $contentHash,
                'metadata' => json_encode($definition['metadata'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]]);

            if ($inserted === 1) {
                return;
            }

            $notification = UserNotification::query()
                ->where($identity)
                ->lockForUpdate()
                ->firstOrFail();
        }

        $changed = $notification->content_hash !== $contentHash
            || $notification->resolved_at !== null;

        $notification->fill([
            'notification_type' => $definition['type'],
            'title' => $definition['title'],
            'message' => $definition['message'],
            'severity' => $definition['severity'],
            'action_url' => $definition['actionUrl'],
            'content_hash' => $contentHash,
            'metadata' => $definition['metadata'],
            'resolved_at' => null,
        ]);

        if ($changed) {
            $notification->read_at = null;
        }

        $notification->save();
    }
}
