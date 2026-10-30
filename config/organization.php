<?php

return [
    'graph_mode' => env('ORGANIZATION_GRAPH_MODE', 'legacy'),
    'graph_user_ids' => env('ORGANIZATION_GRAPH_USER_IDS', ''),
    'graph_branch_ids' => env('ORGANIZATION_GRAPH_BRANCH_IDS', ''),
    'backfill_isolated' => filter_var(env('ORGANIZATION_GRAPH_BACKFILL_ISOLATED', false), FILTER_VALIDATE_BOOL),
    'backfill_database_identity' => env('ORGANIZATION_GRAPH_BACKFILL_DATABASE_IDENTITY', ''),
    'controlled_evidence_marker' => filter_var(env('ORGANIZATION_GRAPH_CONTROLLED_EVIDENCE_MARKER', false), FILTER_VALIDATE_BOOL),
    'backfill_non_production_marker' => filter_var(env('ORGANIZATION_GRAPH_BACKFILL_NON_PRODUCTION_MARKER', false), FILTER_VALIDATE_BOOL),
];
