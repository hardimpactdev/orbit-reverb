<?php

declare(strict_types=1);

return [
    'registry_url' => env('REVERB_REGISTRY_URL'),
    'registry_token' => env('REVERB_REGISTRY_TOKEN'),
    'notification_token' => env('REVERB_NOTIFICATION_TOKEN'),
    'process_id' => env('REVERB_PROCESS_ID'),
    'reconcile_interval' => 30,
    'request_timeout' => 2,
    'heartbeat_interval' => 5,
];
