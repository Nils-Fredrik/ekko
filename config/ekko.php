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
    | Alerts (docs/spec.md 11.1, ADR 0017). The evaluation runs once per tenant and local day, in the first
    | scheduler run after evaluate_after (local time), when yesterday's figures are aggregated. All alerts of
    | that run go out as one message per channel (the digest).
    |
    | thresholds are in the unit of alert_rules.threshold: percentage points or percent. A rule row is created
    | the first time its type is evaluated, with these defaults, and can then be changed per tenant.
    | cooldown_days: the same subject (market and engine, prompt and competitor, ...) is not alerted again
    | within this many days, so a lasting change gives one alert, not one per day.
    | min_responses: the smallest n a figure needs to count in the type's comparison.
    */
    'alerts' => [
        'evaluate_after' => '05:30',
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

];
