<?php

namespace App\Console\Commands;

use App\Services\PersonnelLifecycleService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

final class ProcessPersonnelLifecycle extends Command
{
    protected $signature = 'personnel:lifecycle {--date= : Business date in YYYY-MM-DD format}';

    protected $description = 'Send employment reminders and securely offboard personnel whose employment has ended';

    public function handle(PersonnelLifecycleService $lifecycle): int
    {
        try {
            $date = $this->option('date')
                ? Carbon::createFromFormat('Y-m-d', (string) $this->option('date'))->startOfDay()
                : today();
        } catch (Throwable) {
            $this->error('The --date value must use YYYY-MM-DD format.');

            return self::INVALID;
        }

        $summary = $lifecycle->process($date);
        $this->components->info("Personnel lifecycle processed for {$summary['as_of']}.");
        $this->table(['Result', 'Count'], collect($summary)
            ->except('as_of')
            ->map(fn ($value, $key): array => [str_replace('_', ' ', ucfirst($key)), $value])
            ->values()
            ->all());

        return self::SUCCESS;
    }
}
