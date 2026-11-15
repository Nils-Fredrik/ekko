<?php

// Veiviserens backend (docs/spec.md 13, T25). Alle språk må ha de samme nøklene.

return [
    'invalid_domain' => 'Ange en giltig domän, till exempel exempel.se.',
    'errors' => [
        'brand_limit' => 'Paketet tillåter inte fler varumärken.',
        'market_limit' => 'Paketet tillåter inte fler marknader för det här varumärket.',
        'no_topics' => 'Varumärket har inga teman som kan kopplas mellan marknader.',
        'quota_reached' => 'Månadens kvot eller budget är förbrukad. Analysen kan inte startas.',
        'brand_gone' => 'Varumärket finns inte längre.',
        'fetch_invalid_url' => 'Adressen är inte giltig. Guiden hämtar bara sidor över https.',
        'fetch_blocked_address' => 'Adressen pekar på en intern eller privat IP-adress och kan inte hämtas.',
        'fetch_dns' => 'Domänen kunde inte slås upp.',
        'fetch_too_large' => 'Startsidan är större än 2 MB och kan inte läsas.',
        'fetch_too_many_redirects' => 'Webbplatsen skickar besökare vidare för många gånger.',
        'fetch_timeout' => 'Webbplatsen svarade för sent.',
        'fetch_unsupported_content' => 'Startsidan är inte en vanlig webbsida.',
        'fetch_http_error' => 'Webbplatsen svarade med ett fel.',
        'fetch_connection' => 'Kunde inte ansluta till webbplatsen.',
        'model_unavailable' => 'Analysen är tillfälligt otillgänglig. Försök igen senare.',
        'model_refused' => 'Modellen ville inte skapa förslag för den här webbplatsen.',
        'model_truncated' => 'Modellens svar blev för långt. Försök igen.',
        'invalid_model_output' => 'Modellens svar kunde inte användas. Försök igen.',
        'internal' => 'Analysen misslyckades. Försök igen.',
    ],
    'warnings' => [
        'competitor_dropped' => 'Några konkurrenter saknade giltigt namn eller domän och har utelämnats.',
        'topic_dropped' => 'Några teman var ogiltiga och har utelämnats.',
        'prompt_dropped' => 'Några prompter var ogiltiga och har utelämnats.',
        'prompt_personal_data' => 'Några prompter innehöll e-postadresser eller telefonnummer och har utelämnats.',
    ],
    'issues' => [
        'unknown_engine' => 'Motorn :engine finns inte eller är avstängd.',
        'no_price' => 'Motorn :engine har inget pris.',
        'engine_not_in_plan' => 'Motorn :engine ingår inte i paketet.',
        'frequency_above_plan' => 'Frekvensen för :engine är högre än paketet tillåter.',
        'repetitions_above_plan' => 'Antalet upprepningar för :engine är högre än paketet tillåter.',
        'prompts_above_plan' => 'Antalet prompter är högre än paketet tillåter per marknad.',
        'exceeds_call_quota' => 'Beräknat antal anrop tillsammans med förbrukningen hittills denna månad överstiger anropskvoten.',
        'exceeds_budget' => 'Beräknad kostnad tillsammans med förbrukningen hittills denna månad överstiger budgeten.',
        'exceeds_byok_budget' => 'Beräknad kostnad med egna nycklar tillsammans med förbrukningen hittills denna månad överstiger budgeten för egna nycklar.',
    ],
];
