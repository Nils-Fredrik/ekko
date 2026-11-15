<?php

return [
    'scheduler' => [
        'audience' => env('EKKO_SCHEDULER_AUDIENCE'),
        'service_account' => env('EKKO_SCHEDULER_SERVICE_ACCOUNT'),
    ],
    'poll_seconds' => 900,
    'concurrency' => 2,
    'calls_per_minute' => 30,
    'cloud_run' => [
        'enabled' => env('EKKO_START_WORKER', false),
        'project' => env('GOOGLE_CLOUD_PROJECT'),
        'region' => env('GOOGLE_CLOUD_REGION', 'europe-west1'),
    ],
];
