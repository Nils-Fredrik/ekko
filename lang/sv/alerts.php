<?php

// Varningar (docs/spec.md 11.1, T24): meningarna i dygnsmeddelandet på Slack och e-post. Alla språk måste ha samma nycklar.

return [
    'lines' => [
        'visibility_drop' => ':brand: synligheten i :market (:engine) föll från :from % till :to % (:points procentenheter, n :n_from och :n_to).',
        'competitor_takeover' => ':competitor har tagit förstaplatsen på ”:prompt” för :brand: :from % till :to % av listsvaren (n :n).',
        'citation_lost' => ':brand: en egen domän citeras inte längre på ”:prompt” i :engine (senast citerad :date).',
        'new_competitor' => 'Okänt varumärke ”:name” nämns i :share % av svaren i :market för :brand (n :n).',
        'model_change' => ':engine har bytt huvudmodell: :from till :to (:date).',
        'quota' => 'Förbrukningen har nått :percent % av kvoten eller budgeten för :month.',
        'integration_error' => ':brand: synkroniseringen mot :integration har misslyckats två dagar i rad.',
    ],
    'integrations' => [
        'clickhouse_logs' => 'ClickHouse',
        'ga4' => 'GA4',
        'unknown' => 'integrationen',
    ],
    'slack' => [
        'heading' => '*Ekko, :tenant:* en varning|*Ekko, :tenant:* :count varningar',
    ],
    'mail' => [
        'subject' => 'Ekko, :tenant: en varning|Ekko, :tenant: :count varningar',
        'heading' => 'En varning för :tenant|:count varningar för :tenant',
        'open' => 'Öppna Ekko',
    ],
    'command' => [
        'queued' => 'Varningsutvärdering lagd i kön för :tenants tenanter.',
        'done' => 'Varningar utvärderade för :tenants tenanter.',
        'failed' => 'Varningsutvärderingen misslyckades för :failed av :tenants tenanter (id: :ids).',
    ],
];
