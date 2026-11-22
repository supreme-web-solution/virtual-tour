<?php

return [

    /*
     * Visits and events older than this are deleted by `analytics:prune`.
     * Leads are kept until deleted by the workspace.
     */
    'retention_days' => (int) env('ANALYTICS_RETENTION_DAYS', 395),

    'invitation_expiry_days' => 7,

];
