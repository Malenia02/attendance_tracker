<?php

return [
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
];
