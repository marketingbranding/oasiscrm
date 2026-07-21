<?php

return [
    'read_retention_days' => max(1, (int) env('NOTIFICATION_READ_RETENTION_DAYS', 180)),
    'polling_enabled' => env('NOTIFICATION_POLLING_ENABLED', true),
];
