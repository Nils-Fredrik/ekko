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
