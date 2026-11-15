<?php

return [
    'raw_bucket' => env('EKKO_RAW_BUCKET', 'ekko-raw'),

    'chatgpt_api' => [
        'model' => env('OPENAI_CHATGPT_MODEL', 'gpt-5-mini'),
    ],
];
