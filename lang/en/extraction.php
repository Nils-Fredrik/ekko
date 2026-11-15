<?php

// Report of the extraction evaluation command (docs/spec.md 8.2, T20). All locales must have the same keys.

return [
    'eval' => [
        'responses' => 'Gold set: :count answers (:languages).',
        'value' => 'Value',
        'stage' => 'Stage',
        'precision' => 'Precision',
        'hit_rate' => 'Hit rate',
        'counts' => 'Correct/predicted/expected',
        'model' => 'model output',
        'assembled' => 'stored (assembled)',
        'source_type' => 'source_type: :correct of :total correct (:rate).',
        'retried' => 'Single retries: :count. Answers where the model stage failed: :failed.',
        'usage' => 'Tokens: :input in, :output out. Cost: :cost USD.',
        'waiting' => 'Waiting for batch :batch to finish.',
        'no_key' => 'ANTHROPIC_API_KEY is not set. Set it, or replay a recording with --recorded.',
        'bad_file' => 'The gold set or recording file is missing or invalid.',
    ],
];
