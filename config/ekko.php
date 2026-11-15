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
    | The Claude connection (MCP, docs/spec.md 15.2, ADR 0016). Clients Claude may register per day for the
    | whole application, tool calls per minute per user and OAuth calls per minute per address.
    */
    'mcp' => [
        'clients_per_day' => 500,
        'calls_per_minute' => 120,
        'oauth_per_minute' => 30,
        // Items per page for tools that page their results with a cursor.
        'page_size' => 25,
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

        /*
        | The onboarding wizard's suggestions (docs/spec.md 13, ADR 0018): Claude Sonnet 5.5 through the plain
        | Messages API. Prices are USD per million tokens (A7: the price list is not final); estimated_cost_usd
        | is what one analysis is expected to cost, used for the quota check before it starts.
        */
        'onboarding' => [
            'model' => env('EKKO_ONBOARDING_MODEL', 'claude-sonnet-5-5'),
            'max_tokens' => 16000,
            'effort' => 'medium',
            'input_usd_per_mtok' => '2.00',
            'output_usd_per_mtok' => '10.00',
            'estimated_cost_usd' => '0.15',
        ],
    ],

    /*
    | The onboarding wizard (docs/spec.md 13 and 16, ADR 0018). Fetching a customer's site is the one place
    | where Ekko makes a request to an address the user typed, so the limits are strict.
    */
    'wizard' => [
        'fetch' => [
            'max_pages' => 6,
            'max_redirects' => 3,
            'max_bytes' => 2 * 1024 * 1024,
            'connect_timeout_seconds' => 5,
            'request_timeout_seconds' => 10,
            'total_timeout_seconds' => 40,
            'user_agent' => 'EkkoBot/1.0 (+https://ekko.maksimer.com)',
            'max_text_chars_per_page' => 6000,
        ],
        'suggestions' => [
            'max_competitors' => 10,
            'max_topics' => 8,
            'max_prompts' => 50,
            'max_core_prompts' => 10,
            'max_name_variants' => 10,
        ],
        // How long an unconfirmed proposal stays readable. Proposals live only in the cache, never in the database.
        'proposal_ttl_hours' => 24,
        // Analyses a user may start per minute.
        'analyses_per_minute' => 6,
        // How long a started analysis counts against the quota before it is billed. Longer than the job's timeout
        // (240 s), so only an analysis whose worker died runs out before it is finished.
        'pending_reservation_seconds' => 600,
        'estimate' => [
            // Runs per month for one prompt and one repetition.
            'runs_per_month' => ['daily' => '30', 'weekly' => '4.35', 'monthly' => '1', 'off' => '0'],
            // What one engine call is assumed to use, by engine method. Token prices come from engine_prices.
            'call_usage' => [
                'api' => ['input_tokens' => 400, 'output_tokens' => 1200, 'search_calls' => 2],
                'ui_scrape' => ['input_tokens' => 0, 'output_tokens' => 0, 'search_calls' => 0],
                'serp' => ['input_tokens' => 0, 'output_tokens' => 0, 'search_calls' => 0],
            ],
            // What extracting one answer is assumed to use (batch price).
            'extraction_usage' => ['input_tokens' => 1500, 'output_tokens' => 400],
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
