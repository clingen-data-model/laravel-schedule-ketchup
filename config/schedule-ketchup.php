<?php

return [
    // Rows older than this are deleted by schedule-runs:prune.
    'prune_after_days' => env('SCHEDULE_RUNS_PRUNE_AFTER_DAYS', 90),
];
