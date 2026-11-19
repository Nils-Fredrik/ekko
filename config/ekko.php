<?php

use App\ActionEngine\Sources\CrawlerAccessSource;
use App\ActionEngine\Sources\FactCorrectionSource;
use App\ActionEngine\Sources\NewCompetitorSource;
use App\ActionEngine\Sources\OwnedContentSource;
use App\ActionEngine\Sources\SourceGapSource;
use App\Work\Handlers\AggregationWorkHandler;
use App\Work\Handlers\ExtractionWorkHandler;
use App\Work\Handlers\UsageRollupWorkHandler;

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
    | Durable event-driven processing work (docs/spec.md 7.1, ADR 0032, T40-F2). publisher: how a wake-up is
    | delivered after commit, "queue" (ProcessWorkSignal on the default queue), "cloud_tasks" (T40-F4, below) or "none"
    | (only the recovery sweep). lease_seconds must exceed ProcessWorkSignal's timeout (300 s). A failed attempt waits
    | retry_base_seconds * 2^(attempt-1), at most retry_max_seconds, plus up to retry_jitter_seconds; failures
    | are retried with backoff and logged as errors from alert_after_attempts on; a failing streak longer than
    | stop_after_seconds (24 h) stops the signal and alerts (work_stopped, ADR 0029) until a new signal or ekko:work:retry. recover_after_seconds: how long
    | a due signal may wait before the sweep publishes it again. handlers: work kind => WorkHandler class; a kind
    | without one stays pending.
    |
    | chaining (T40-F3, EKKO_WORK_CHAINING, off by default): when on, collection, extraction and usage register their
    | follow-up work as signals (collected answer -> extraction -> daily aggregates, usage event -> monthly usage), and
    | a submitted model batch is checked through a deferred signal instead of PollExtractionBatch releasing itself.
    | The scheduled extraction-poll, aggregate and usage-rollup keep running as the fallback until T40-F4.
    | extraction.group_wait_seconds: how long a collected answer waits for the rest of its collection group (run
    | batch) before it is submitted anyway; a finished group or a full model batch goes at once. extraction.status_*:
    | the status checks of a submitted batch, first after status_first_seconds, then after the batch's age so far
    | (doubling), at most status_max_seconds apart, plus up to status_jitter_seconds; a batch still not ended after
    | status_give_up_hours is given up like PollExtractionBatch does (deterministic results, llm_failed).
    |
    | cloud_tasks (T40-F4, ADR 0033, EKKO_WORK_PUBLISHER=cloud_tasks): targeted wake-ups through Cloud Tasks. Each
    | wake-up is an HTTP task with a deterministic id to the fixed routes /internal/work/wake (one signal) and
    | /internal/work/queue-wake (waiting queue jobs) on app.url, with a Google OIDC token of service_account for
    | audience. The routes accept only that identity and audience. publish_attempts: tries per publish on a transient
    | API error (ALREADY_EXISTS counts as published). wake.launch_cooldown_seconds: after web has woken the fixed
    | dispatcher (ADR 0030), further deliveries wait this long before they wake it again; until the work is picked
    | up, a delivery answers 425 so Cloud Tasks comes back (wake.confirm_after_seconds as Retry-After).
    */
    'work' => [
        'chaining' => (bool) env('EKKO_WORK_CHAINING', false),
        'extraction' => [
            'group_wait_seconds' => (int) env('EKKO_WORK_EXTRACTION_GROUP_WAIT_SECONDS', 600),
            'status_first_seconds' => (int) env('EKKO_WORK_EXTRACTION_STATUS_FIRST_SECONDS', 60),
            'status_max_seconds' => (int) env('EKKO_WORK_EXTRACTION_STATUS_MAX_SECONDS', 900),
            'status_jitter_seconds' => (int) env('EKKO_WORK_EXTRACTION_STATUS_JITTER_SECONDS', 15),
            'status_give_up_hours' => 25,
        ],
        'publisher' => env('EKKO_WORK_PUBLISHER', 'queue'),
        'cloud_tasks' => [
            'project' => env('GOOGLE_CLOUD_PROJECT'),
            'location' => env('GOOGLE_CLOUD_REGION', 'europe-west1'),
            'queue' => env('EKKO_WORK_TASKS_QUEUE'),
            'service_account' => env('EKKO_WORK_TASKS_SERVICE_ACCOUNT'),
            'audience' => env('EKKO_WORK_TASKS_AUDIENCE'),
            'dispatch_deadline_seconds' => 60,
            'publish_attempts' => 3,
        ],
        'wake' => [
            'launch_cooldown_seconds' => 120,
            'confirm_after_seconds' => 15,
        ],
        'lease_seconds' => 360,
        'retry_base_seconds' => 30,
        'retry_max_seconds' => 3600,
        'retry_jitter_seconds' => 15,
        'alert_after_attempts' => 5,
        'stop_after_seconds' => (int) env('EKKO_WORK_STOP_AFTER_SECONDS', 86400),
        'recover_after_seconds' => 300,
        'handlers' => [
            'extraction' => ExtractionWorkHandler::class,
            'aggregation' => AggregationWorkHandler::class,
            'usage_rollup' => UsageRollupWorkHandler::class,
        ],
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
    | Whether the platform admin and partner advisors must use two-factor authentication (docs/spec.md 5.4).
    | On unless EKKO_REQUIRE_TWO_FACTOR=false, which the owner may set while testing before launch (T07-F1).
    | A user who has set up two-factor authentication keeps it either way.
    */
    // Only an explicit false switches it off: an empty or unknown value keeps the requirement.
    'require_two_factor' => env('EKKO_REQUIRE_TWO_FACTOR', true) !== false,

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
        'social.confirm',
        'social.callback',
        'logout',
        'locale.update',
        'verification.*',
    ],

    /*
    | Usage the platform pays for (docs/spec.md 12.2, ADR 0034, owner decision on Board #996). Usage events of
    | these kinds are booked in usage_events and on the Anthropic account (ADR 0023) like any other call, shown
    | in the admin usage overview and included in the Anthropic forecast, but they are left out of the tenant's
    | call quota, plan budget, quota alerts and invoice basis (App\Usage\PlatformPaidUsage). A helper call that
    | the platform pays for (T48's fact-check, for example) adds its kind here.
    */
    'usage' => [
        'platform_paid_kinds' => ['actions', 'factcheck'],
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

        /*
        | Fact check (docs/spec.md 11.6, ADR 0036): one call per answer judges the claims about the brand against its
        | facts. Sonnet first; the model id is configuration so it can be switched to Opus if accuracy is too low
        | (EKKO_FACTCHECK_MODEL). Prices are USD per million tokens, so change them with the model; estimated_cost_usd is
        | reserved on the Anthropic account before each call (ADR 0023).
        */
        'factcheck' => [
            'model' => env('EKKO_FACTCHECK_MODEL', 'claude-sonnet-5-5'),
            'max_tokens' => 4000,
            'effort' => 'low',
            'input_usd_per_mtok' => env('EKKO_FACTCHECK_INPUT_USD_PER_MTOK', '2.00'),
            'output_usd_per_mtok' => env('EKKO_FACTCHECK_OUTPUT_USD_PER_MTOK', '10.00'),
            'estimated_cost_usd' => env('EKKO_FACTCHECK_ESTIMATED_COST_USD', '0.02'),
            'timeout_seconds' => 60,
        ],

        /*
        | Wording of actions (docs/spec.md 11.3, ADR 0034): Claude Sonnet 5.5 writes title, description and steps
        | from the evidence. Prices are USD per million tokens; estimated_cost_usd is reserved on the Anthropic
        | account before each call (ADR 0023).
        */
        'actions' => [
            'model' => env('EKKO_ACTIONS_MODEL', 'claude-sonnet-5-5'),
            'max_tokens' => 2000,
            'effort' => 'low',
            'input_usd_per_mtok' => '2.00',
            'output_usd_per_mtok' => '10.00',
            'estimated_cost_usd' => '0.03',
            // One call may take this long; with ekko.actions.word_seconds the job stays within the worker timeout.
            'timeout_seconds' => 60,
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
            'max_facts' => 8,
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
        'disk' => filled(env('EKKO_REPORTS_BUCKET')) ? 'reports' : 'local',
        // Chromium for spatie/laravel-pdf. The jobs image sets CHROME_PATH.
        'chrome_binary' => env('CHROME_PATH'),
    ],

    /*
    | Actions (docs/spec.md 11.3, ADR 0034). ekko:generate-actions runs weekly; figures are read for the latest
    | window_days tenant-local days before today. Thresholds can change without a code change.
    */
    'actions' => [
        'window_days' => 30,
        // Fewer answers than this in a market (or for the brand) and a source does not judge it, so nothing expires.
        'min_answers' => 30,
        // Expected effect per month (affected answers × competitor-only share) from which each level starts.
        'impact_levels' => [2 => 2, 3 => 5, 4 => 15, 5 => 40],
        // Open actions not supported by this many generations in a row expire.
        'expire_after_generations' => 2,
        // Model calls per tenant and generation; the rest keep the template text until the next generation.
        'max_formulations' => 40,
        // No model call starts later than this many seconds after the job started, nor later than the job's
        // timeout (420 s) minus one call (anthropic.actions.timeout_seconds) and a margin. The command also sets
        // a deadline for all tenants in sync mode.
        'word_seconds' => 300,
        'command_word_seconds' => 2700,
        // The sources that produce candidates, in order.
        'sources' => [
            SourceGapSource::class,
            OwnedContentSource::class,
            NewCompetitorSource::class,
            CrawlerAccessSource::class,
            FactCorrectionSource::class,
        ],
        'source_gap' => [
            'min_gap_responses' => 3,
            'max_per_market' => 5,
        ],
        'owned_content' => [
            'min_prompt_answers' => 3,
            // A prompt has low visibility below this share.
            'max_visibility' => 0.5,
            'min_prompts' => 2,
        ],
        'new_competitor' => [
            // As the alert (docs/spec.md 11.1): the brand's new_competitor rule threshold, else this (percent).
            'window_days' => 7,
            'default_threshold_percent' => 10,
        ],
        'fact_correction' => [
            // A fact becomes an action when this many answers in the window contradict it (docs/spec.md 11.6).
            'min_responses' => 2,
            // Claims shown as examples in the evidence.
            'max_claim_examples' => 3,
            // Sources (pages) listed in the evidence.
            'max_sources' => 5,
        ],
        'crawler_access' => [
            'window_days' => 14,
            // Search bots that should fetch the brand's pages (keys of bot_logs.bots).
            'bots' => ['oai_searchbot', 'perplexitybot', 'claude_searchbot'],
        ],
    ],

    /*
    | Fact check (docs/spec.md 11.6, ADR 0036): the facts a brand is checked against and the claims kept per answer.
    */
    'facts' => [
        'max_per_brand' => 50,
        'max_claims_per_response' => 20,
        // Answers judged per job run, and how long back an unjudged answer is still worth judging.
        'max_checks_per_run' => 60,
        'max_age_days' => 35,
        // No call starts later than this many seconds after the job started (the job's timeout is 420 s).
        'check_seconds' => 300,
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
