<?php

// Alerts (docs/spec.md 11.1, T24): the sentences of the daily message on Slack and e-mail. All languages must have the same keys.

return [
    'lines' => [
        'visibility_drop' => ':brand: visibility in :market (:engine) fell from :from% to :to% (:points percentage points, n :n_from and :n_to).',
        'competitor_takeover' => ':competitor has taken first place on ":prompt" for :brand: :from% to :to% of the list answers (n :n).',
        'citation_lost' => ':brand: an own domain is no longer cited on ":prompt" in :engine (last cited :date).',
        'new_competitor' => 'Unknown brand ":name" is mentioned in :share% of the answers in :market for :brand (n :n).',
        'model_change' => ':engine has changed its main model: :from to :to (:date).',
        'quota' => 'Usage has reached :percent% of the quota or budget for :month.',
        'integration_error' => ':brand: the sync against :integration has failed two days in a row.',
    ],
    'integrations' => [
        'clickhouse_logs' => 'ClickHouse',
        'ga4' => 'GA4',
        'unknown' => 'the integration',
    ],
    'slack' => [
        'heading' => '*Ekko, :tenant:* one alert|*Ekko, :tenant:* :count alerts',
    ],
    'mail' => [
        'subject' => 'Ekko, :tenant: one alert|Ekko, :tenant: :count alerts',
        'heading' => 'One alert for :tenant|:count alerts for :tenant',
        'open' => 'Open Ekko',
    ],
    'command' => [
        'queued' => 'Alert evaluation queued for :tenants tenants.',
        'done' => 'Alerts evaluated for :tenants tenants.',
        'failed' => 'Alert evaluation failed for :failed of :tenants tenants (ids: :ids).',
    ],
];
