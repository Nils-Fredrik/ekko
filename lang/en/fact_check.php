<?php

return [
    'errors' => [
        'empty' => 'The fact cannot be empty.',
        'too_long' => 'A fact can have at most :max characters.',
        'limit' => 'A brand can have at most :max facts.',
        'duplicate' => 'This fact is already registered for the brand.',
    ],
    'eval' => [
        'cases' => ':count answers and :claims expected claims (:languages).',
        'bad_file' => 'The gold set is missing or invalid.',
        'no_key' => 'ANTHROPIC_API_KEY is missing. Use --recorded to replay recorded output.',
        'verdict' => 'Verdict',
        'precision' => 'Precision',
        'hit_rate' => 'Hit rate',
        'counts' => 'Correct/predicted/expected',
        'accuracy' => 'Accuracy: :correct of :total expected verdicts are right (:rate).',
        'false_claims' => 'Claims on facts without an expected verdict: :count.',
        'by_language' => 'Accuracy per language: :languages.',
        'failed' => 'Answers without a usable result: :failed.',
        'usage' => 'Usage: :input input tokens, :output output tokens, cost :cost USD.',
    ],
];
