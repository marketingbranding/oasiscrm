<?php

return [
    'database_operational_enabled' => (bool) env('OASIS_DATABASE_OPERATIONAL_ENABLED', false),
    'consumer_progress_operational_enabled' => (bool) env('OASIS_CONSUMER_PROGRESS_OPERATIONAL_ENABLED', false),
    'spreadsheet_sync_enabled' => (bool) env('OASIS_SPREADSHEET_SYNC_ENABLED', false),

    // legacy is the safe production default; local is an explicit rollout mode.
    'consumer_progress_read_source' => env('CONSUMER_PROGRESS_READ_SOURCE', 'legacy'),
    'dashboard_consumer_read_source' => env('DASHBOARD_CONSUMER_READ_SOURCE', 'legacy'),
];

// Allowed values: legacy, local. Unknown values stay on legacy for both read foundations.
// Dashboard local rollout is operator-only; no end-user setting exists.
