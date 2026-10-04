<?php

return [
    'scheduler_max_age_minutes' => (int) env('SCHEDULER_HEARTBEAT_MAX_AGE_MINUTES', 3),
    'backup_max_age_hours' => (int) env('BACKUP_HEARTBEAT_MAX_AGE_HOURS', 36),
    'backup_signing_secret' => env('BACKUP_HEARTBEAT_SECRET'),
    'backup_signature_ttl_seconds' => (int) env('BACKUP_HEARTBEAT_SIGNATURE_TTL_SECONDS', 300),
    'release' => env('RENDER_GIT_COMMIT', env('VERCEL_GIT_COMMIT_SHA')),
];
