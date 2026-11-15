<?php

// Veiviserens backend (docs/spec.md 13, T25). Alle språk må ha de samme nøklene.

return [
    'invalid_domain' => 'Oppgi et gyldig domene, for eksempel eksempel.no.',
    'errors' => [
        'brand_limit' => 'Pakken tillater ikke flere merkevarer.',
        'market_limit' => 'Pakken tillater ikke flere markeder for denne merkevaren.',
        'no_topics' => 'Merkevaren har ingen temaer som kan kobles på tvers av markeder.',
        'quota_reached' => 'Månedens kvote eller budsjett er brukt opp. Analysen kan ikke startes.',
        'brand_gone' => 'Merkevaren finnes ikke lenger.',
        'fetch_invalid_url' => 'Adressen er ikke gyldig. Veiviseren henter bare sider over https.',
        'fetch_blocked_address' => 'Adressen peker til en intern eller privat IP-adresse og kan ikke hentes.',
        'fetch_dns' => 'Domenet kunne ikke slås opp.',
        'fetch_too_large' => 'Forsiden er større enn 2 MB og kan ikke leses.',
        'fetch_too_many_redirects' => 'Nettstedet sender besøkende videre for mange ganger.',
        'fetch_timeout' => 'Nettstedet svarte for sent.',
        'fetch_unsupported_content' => 'Forsiden er ikke en vanlig nettside.',
        'fetch_http_error' => 'Nettstedet svarte med en feil.',
        'fetch_connection' => 'Kunne ikke koble til nettstedet.',
        'model_unavailable' => 'Analysen er midlertidig utilgjengelig. Prøv igjen senere.',
        'model_refused' => 'Modellen ville ikke lage forslag for dette nettstedet.',
        'model_truncated' => 'Modellens svar ble for langt. Prøv igjen.',
        'invalid_model_output' => 'Modellens svar kunne ikke brukes. Prøv igjen.',
        'internal' => 'Analysen feilet. Prøv igjen.',
    ],
    'warnings' => [
        'competitor_dropped' => 'Noen konkurrenter manglet gyldig navn eller domene og er utelatt.',
        'topic_dropped' => 'Noen temaer var ugyldige og er utelatt.',
        'prompt_dropped' => 'Noen prompts var ugyldige og er utelatt.',
        'prompt_personal_data' => 'Noen prompts inneholdt e-postadresser eller telefonnummer og er utelatt.',
    ],
    'issues' => [
        'unknown_engine' => 'Motoren :engine finnes ikke eller er slått av.',
        'no_price' => 'Motoren :engine har ingen pris.',
        'engine_not_in_plan' => 'Motoren :engine er ikke med i pakken.',
        'frequency_above_plan' => 'Frekvensen for :engine er høyere enn pakken tillater.',
        'repetitions_above_plan' => 'Antall gjentakelser for :engine er høyere enn pakken tillater.',
        'prompts_above_plan' => 'Antall prompts er høyere enn pakken tillater per marked.',
        'exceeds_call_quota' => 'Estimert antall kall sammen med forbruket hittil denne måneden overstiger kallkvoten.',
        'exceeds_budget' => 'Estimert kostnad sammen med forbruket hittil denne måneden overstiger budsjettet.',
        'exceeds_byok_budget' => 'Estimert kostnad med egne nøkler sammen med forbruket hittil denne måneden overstiger budsjettet for egne nøkler.',
    ],
];
