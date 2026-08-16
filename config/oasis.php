<?php

return [
    // legacy is the safe production default; local is an explicit rollout mode.
    'consumer_progress_read_source' => env('CONSUMER_PROGRESS_READ_SOURCE', 'legacy'),
    'dashboard_consumer_read_source' => env('DASHBOARD_CONSUMER_READ_SOURCE', 'legacy'),
];

// Allowed values: legacy, local. Unknown values stay on legacy for both read foundations.
// Dashboard local rollout is operator-only; no end-user setting exists.
