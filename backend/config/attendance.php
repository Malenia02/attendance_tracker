<?php

return [
    'notification_retention_days' => (int) env('NOTIFICATION_RETENTION_DAYS', 90),
    /*
    |--------------------------------------------------------------------------
    | DTR Certification Signing Key
    |--------------------------------------------------------------------------
    |
    | Production should provide a dedicated random key. Falling back to the
    | application key keeps existing local installations operational.
    |
    */
    'dtr_signing_key' => env('DTR_SIGNING_KEY', env('APP_KEY')),

    'qr_signing_key' => env('QR_SIGNING_KEY', env('APP_KEY')),

    'maximum_location_accuracy_meters' => (float) env(
        'MAXIMUM_LOCATION_ACCURACY_METERS',
        100
    ),

    // Short caching keeps the dashboard responsive on low-resource test
    // instances while still reflecting attendance changes promptly.
    'dashboard_cache_seconds' => (int) env('DASHBOARD_CACHE_SECONDS', 30),

    // The free Render web service has limited CPU/RAM and no dedicated queue
    // worker. Keep synchronous document batches deliberately small.
    'dtr_sync_batch_limit' => (int) env('DTR_SYNC_BATCH_LIMIT', 20),

    // DTRs become due on the period cutoff and overdue after this grace
    // window. Notifications begin shortly before the cutoff.
    'dtr_submission_grace_days' => (int) env('DTR_SUBMISSION_GRACE_DAYS', 2),
    'dtr_reminder_days_before' => (int) env('DTR_REMINDER_DAYS_BEFORE', 3),
];
