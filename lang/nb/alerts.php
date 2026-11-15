<?php

// Varsler (docs/spec.md 11.1, T24): setningene i døgnmeldingen på Slack og e-post. Alle språk må ha de samme nøklene.

return [
    'lines' => [
        'visibility_drop' => ':brand: synlighet i :market (:engine) falt fra :from % til :to % (:points prosentpoeng, n :n_from og :n_to).',
        'competitor_takeover' => ':competitor har tatt førsteplassen på «:prompt» for :brand: :from % til :to % av listesvarene (n :n).',
        'citation_lost' => ':brand: eget domene er ikke lenger sitert på «:prompt» i :engine (sist sitert :date).',
        'new_competitor' => 'Ukjent merkevare «:name» nevnes i :share % av svarene i :market for :brand (n :n).',
        'model_change' => ':engine har byttet hovedmodell: :from til :to (:date).',
        'quota' => 'Forbruket har nådd :percent % av kvote eller budsjett for :month.',
        'integration_error' => ':brand: synkroniseringen mot :integration har feilet to dager på rad.',
    ],
    'integrations' => [
        'clickhouse_logs' => 'ClickHouse',
        'ga4' => 'GA4',
        'unknown' => 'integrasjonen',
    ],
    'slack' => [
        'heading' => '*Ekko, :tenant:* ett varsel|*Ekko, :tenant:* :count varsler',
    ],
    'mail' => [
        'subject' => 'Ekko, :tenant: ett varsel|Ekko, :tenant: :count varsler',
        'heading' => 'Ett varsel for :tenant|:count varsler for :tenant',
        'open' => 'Åpne Ekko',
    ],
    'command' => [
        'queued' => 'Varselsevaluering lagt i køen for :tenants tenanter.',
        'done' => 'Varsler evaluert for :tenants tenanter.',
        'failed' => 'Varselsevaluering feilet for :failed av :tenants tenanter (id: :ids).',
    ],
];
