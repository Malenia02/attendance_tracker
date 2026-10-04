<?php

namespace App\Console\Commands;

use App\Models\SystemHealthCheck;
use Illuminate\Console\Command;

final class RecordSystemHeartbeat extends Command
{
    protected $signature = 'system:heartbeat';

    protected $description = 'Record a persistent heartbeat from the Laravel scheduler';

    public function handle(): int
    {
        SystemHealthCheck::recordSuccess('scheduler', [
            'source' => 'laravel_scheduler',
        ]);

        $this->components->info('Scheduler heartbeat recorded.');

        return self::SUCCESS;
    }
}
