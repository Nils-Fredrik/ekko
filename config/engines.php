<?php

return [
    'raw_bucket' => env('EKKO_RAW_BUCKET', 'ekko-raw'),

    'platform_keys' => [
        'anthropic' => env('ANTHROPIC_API_KEY', ''),
        'perplexity' => env('PERPLEXITY_API_KEY', ''),
    ],

    'chatgpt_api' => [
        'model' => env('OPENAI_CHATGPT_MODEL', 'gpt-5-mini'),
    ],
];
