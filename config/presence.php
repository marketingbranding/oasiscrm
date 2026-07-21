<?php

return [
    'enabled' => env('PRESENCE_ENABLED', true),
    'heartbeat_seconds' => max(20, (int) env('PRESENCE_HEARTBEAT_SECONDS', 25)),
    'offline_seconds' => max(30, (int) env('PRESENCE_OFFLINE_SECONDS', 60)),
    'cleanup_hours' => max(1, (int) env('PRESENCE_CLEANUP_HOURS', 24)),
];
