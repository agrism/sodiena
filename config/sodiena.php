<?php

return [
    'ingestion_v2_enabled' => (bool) env('INGESTION_V2_ENABLED', false),
    'reconciliation_start_buffer_minutes' => (int) env('RECONCILIATION_START_BUFFER_MINUTES', 120),
];
