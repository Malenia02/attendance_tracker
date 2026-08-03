<?php

namespace App\Console\Commands;

use App\Models\UserNotification;
use Illuminate\Console\Command;

final class PruneUserNotifications extends Command
{
    protected $signature = 'notifications:prune {--days= : Retention period in days}';

    protected $description = 'Delete old resolved notifications and acknowledged event notifications';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('attendance.notification_retention_days', 90));
        $days = max(30, min($days, 3650));
        $cutoff = now()->subDays($days);
        $deleted = UserNotification::query()
            ->where('created_at', '<', $cutoff)
            ->where(function ($query): void {
                $query->whereNotNull('resolved_at')
                    ->orWhere(function ($events): void {
                        $events->where('notification_key', 'like', 'event:%')
                            ->whereNotNull('read_at');
                    });
            })
            ->delete();

        $this->info("Pruned {$deleted} notification records older than {$days} days.");

        return self::SUCCESS;
    }
}
