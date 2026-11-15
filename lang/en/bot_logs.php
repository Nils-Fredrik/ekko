<?php

return [
    'title' => 'Access log uploads',
    'brand' => 'Brand',
    'domain' => 'Domain',
    'file' => 'Gzip access log',
    'instructions' => 'Upload a complete nginx or Apache combined log for the selected domain. Each UTC day in the file replaces the saved counts for that day.',
    'upload' => 'Upload log',
    'unavailable' => 'Log uploads require an active tenant and a brand with a registered domain and no ClickHouse integration.',
    'recent_uploads' => 'Recent uploads',
    'pending' => 'Queued',
    'processed' => 'Processed',
    'rejected' => 'Import failed',
    'clickhouse_enabled' => 'This brand already uses ClickHouse.',
    'invalid_gzip' => 'Choose a gzip-compressed log file.',
    'invalid_date' => 'Expected a completed UTC date in YYYY-MM-DD format.',
];
