<?php

return [
    'raw_bucket' => env('EKKO_RAW_BUCKET', 'ekko-raw'),

    'chatgpt_api' => [
        'model' => env('OPENAI_CHATGPT_MODEL', 'gpt-5-mini'),
    ],

    'gemini_api' => [
        'model' => env('GOOGLE_GEMINI_MODEL', 'gemini-3.8-flash'),
    ],

    'perplexity_api' => [
        'api_key' => env('PERPLEXITY_API_KEY', ''),
        'model' => env('PERPLEXITY_MODEL', 'perplexity/sonar'),
    ],
];
