<?php

return [
    // Deployment gates also stop already queued work; historical results remain readable.
    'disabled_providers' => array_filter(array_map('trim', explode(',', env('EKKO_DISABLED_ENGINE_PROVIDERS', '')))),
    'gemini_grounding_approved' => env('EKKO_GEMINI_GROUNDING_APPROVED', false),

    'raw_bucket' => env('EKKO_RAW_BUCKET', 'ekko-raw'),

    'chatgpt_api' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_CHATGPT_MODEL', 'gpt-5-mini-2025-08-07'),
    ],

    'dataforseo' => [
        'credentials' => env('DATAFORSEO_CREDENTIALS'),
        'locations' => [
            'NO' => 2578,
            'SE' => 2752,
        ],
        'languages' => [
            'nb' => 'no',
            'sv' => 'sv',
        ],
    ],

    'gemini_api' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GOOGLE_GEMINI_MODEL', 'gemini-3.8-flash'),
    ],

    'perplexity_api' => [
        'api_key' => env('PERPLEXITY_API_KEY', ''),
        'model' => env('PERPLEXITY_MODEL', 'perplexity/sonar'),
    ],
];
