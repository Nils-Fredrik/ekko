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
    | Alerts (docs/spec.md 11.1, ADR 0019). The evaluation runs once per tenant and local day, in the first
    | scheduler run after evaluate_after (local time), when yesterday's figures are aggregated, but not before
    | yesterday's runs are extracted unless evaluate_wait_until has passed. All alerts of that run go out as one
    | message per channel (the digest). digest_max_age_days: older alerts that never got a digest stay in the list.
    |
    | thresholds are in the unit of alert_rules.threshold: percentage points or percent. A rule row is created
    | the first time its type is evaluated, with these defaults, and can then be changed per tenant.
    | cooldown_days: the same subject (market and engine, prompt and competitor, ...) is not alerted again
    | within this many days, so a lasting change gives one alert, not one per day.
    | min_responses: the smallest n a figure needs to count in the type's comparison.
    */
    'alerts' => [
        'evaluate_after' => '05:30',
        'evaluate_wait_until' => '12:00',
        'digest_max_age_days' => 3,
        'default_channels' => ['in_app', 'email', 'slack'],
        'thresholds' => [
            'visibility_drop' => 10,
            'competitor_takeover' => 50,
            'new_competitor' => 10,
        ],
        'cooldown_days' => [
            'default' => 7,
            'new_competitor' => 30,
        ],
        'min_responses' => [
            'visibility_drop' => 30,
            'competitor_takeover' => 5,
            'new_competitor' => 30,
        ],
        'window_days' => 7,
        'fresh_days' => 3,
        'round_history_days' => 35,
        'model_history_days' => 14,
        'delivery_attempts' => 3,
        'slack_hosts' => ['hooks.slack.com'],
    ],

    /*
    |--------------------------------------------------------------------------
    | REST API v1 (docs/spec.md 15.1, T39-F10)
    |--------------------------------------------------------------------------
    |
    | Calls per minute per token and per user (all of a user's tokens together), how many API tokens a user
    | can hold, and how many days a new token lives.
    */
    'api' => [
        'calls_per_minute_per_token' => 60,
        'calls_per_minute_per_user' => 120,
        'tokens_per_user' => 20,
        'token_days' => 365,
    ],

    /*
    | The Claude connection (MCP, docs/spec.md 15.2, ADR 0016). Client registration: per address per day,
    | and a ceiling for unused clients in the whole application (T39-F5). An unused client (no connection
    | yet) is deleted after unused_client_minutes. Also tool calls per minute per user and OAuth calls per
    | minute per address.
    */
    'mcp' => [
        'clients_per_ip_per_day' => 20,
        'unused_clients_max' => 500,
        'unused_client_minutes' => 60,
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
        // Null until the owner confirms the workspace allocation and billing anchor.
        'monthly_pool_usd' => env('EKKO_ANTHROPIC_POOL_USD'),
        'cycle_start_day' => (int) env('EKKO_ANTHROPIC_CYCLE_START_DAY', 1),
        'insight_estimate_usd' => '0.05',
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
        // How long a started analysis counts against the quota before it is billed: the longest wait for a worker
        // (if starting it after the request fails, the next dispatch-due or extraction-poll starts it, every 15
        // minutes) plus the job's timeout (240 s), with margin. Only an analysis whose worker died runs out first.
        'pending_reservation_seconds' => 1800,
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

    /*
    | Where a report PDF is stored and downloaded (docs/spec.md 11.2). T32 writes to this disk
    | under reports/{tenant}/. The ekko-reports bucket replaces local once it exists.
    */
    'reports' => [
        'disk' => 'local',
        // Chromium for spatie/laravel-pdf. The jobs image sets CHROME_PATH.
        'chrome_binary' => env('CHROME_PATH'),
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
