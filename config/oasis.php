<?php

return [
    // legacy is the safe production default; local is an explicit rollout mode.
    'consumer_progress_read_source' => env('CONSUMER_PROGRESS_READ_SOURCE', 'legacy'),
];

// Allowed values: legacy, local.
// Unknown values are treated as legacy by KonsumenProgressReadService.
