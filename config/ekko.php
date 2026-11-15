<?php

return [

    /*
    | Languages the interface can be shown in (docs/spec.md 14.1). nb is the default.
    */
    'locales' => ['nb', 'sv', 'en'],

    /*
    | How many days back ekko:aggregate rebuilds when no date is given (docs/spec.md 9, ADR 0012), besides today.
    | Answers are only counted once extracted, and batch extraction can take more than a day.
    */
    'aggregate' => [
        'lookback_days' => 3,
    ],

    /*
    | Days a team invitation can be accepted (docs/spec.md 5.4). Enforced server-side when the
    | invitation is accepted and when a single sign-on account is linked.
    */
    'invitation_ttl_days' => 7,

    /*
    | Single sign-on providers (docs/spec.md 5.4). A provider is offered on the login page only
    | when its client id and secret are set. Microsoft is left out for now (ADR 0008).
    */
    'sso_providers' => ['google'],

    /*
    | Route names a user who must have two-factor authentication may use before it is set up:
    | enough to set it up, confirm the password and log out.
    */
    'two_factor_exempt_routes' => [
        'profile.show',
        'two-factor.*',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'logout',
        'locale.update',
        'verification.*',
    ],

    /*
    | Model-based extraction, the second stage of answer analysis (docs/spec.md 8.2, ADR 0011). Prices are
    | USD per million tokens for the prompt sizes an answer gives (up to 100K tokens); the batch price factor
    | (0.5 = half price) applies to Message Batches only, not to the single retry through the Messages API.
    */
    'anthropic' => [
        'extraction' => [
            'model' => env('EKKO_EXTRACTION_MODEL', 'claude-haiku-5-5'),
            'max_tokens' => 16000,
            'effort' => 'low',
            'batch_size' => 100,
            'poll_delay_seconds' => 120,
            'input_usd_per_mtok' => '0.10',
            'output_usd_per_mtok' => '0.50',
            'batch_price_factor' => '0.5',
        ],
    ],

    'bot_logs' => [
        // User-agent substrings are matched case-insensitively, in the configured order.
        'bots' => [
            'OAI-SearchBot' => 'oai_searchbot',
            'GPTBot' => 'gptbot',
            'ChatGPT-User' => 'chatgpt_user',
            'PerplexityBot' => 'perplexitybot',
            'Perplexity-User' => 'perplexity_user',
            'ClaudeBot' => 'claudebot',
            'Claude-User' => 'claude_user',
            'Claude-SearchBot' => 'claude_searchbot',
            'Google-Extended' => 'google_extended',
            'GoogleOther' => 'google_other',
        ],
        'clickhouse_endpoints' => array_values(array_filter(array_map('trim', explode(',', (string) env('EKKO_CLICKHOUSE_ENDPOINTS', ''))))),
        'max_upload_kib' => 2048,
        'max_expanded_bytes' => 64 * 1024 * 1024,
    ],

];
