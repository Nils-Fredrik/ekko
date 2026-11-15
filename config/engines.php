<?php

return [
    'raw_bucket' => env('EKKO_RAW_BUCKET', 'ekko-raw'),

    'chatgpt_api' => [
        'api_key' => env('OPENAI_API_KEY', ''),
        'model' => env('OPENAI_CHATGPT_MODEL', 'gpt-5-mini'),
    ],
];
