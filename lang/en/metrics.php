<?php

// Metric names (docs/spec.md 9), markers (7.5) and messages from ekko:aggregate (T21). All languages must have the same keys.

return [
    'names' => [
        'visibility' => 'Visibility',
        'recommendation_rate' => 'Recommendation rate',
        'share_of_voice' => 'Share of voice',
        'average_position' => 'Average position',
        'first_place_rate' => 'First-place rate',
        'citation_rate' => 'Citation rate',
        'sentiment' => 'Sentiment',
        'stability' => 'Stability',
        'source_share' => 'Source share',
        'source_gap' => 'Source gap',
        'bot_hits' => 'AI bot hits',
        'ai_traffic' => 'AI traffic',
    ],
    'low_sample' => 'Few answers (n < :min)',
    'model_change' => 'Model change: :engine went from :from to :to',
    'aggregate' => [
        'queued' => 'Aggregation queued for :tenants tenants.',
        'done' => 'Aggregation finished for :tenants tenants.',
        'bad_date' => 'Invalid date or date range. Use the format YYYY-MM-DD.',
        'failed' => 'Aggregation failed for :failed of :tenants tenants (id :ids). The others are done.',
    ],
];
