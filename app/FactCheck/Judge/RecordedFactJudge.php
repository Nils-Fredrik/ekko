<?php

namespace App\FactCheck\Judge;

use App\FactCheck\FactCheckRequest;
use InvalidArgumentException;

/**
 * Replays recorded model output instead of calling the API: for the offline evaluation (`--recorded`) and for tests.
 * The file maps a case id to what the model returned:
 *
 *   {"outputs": {"nb-01": {"text": {"claims": [...]}, "input_tokens": 900, "output_tokens": 90}}}
 *
 * "text" is either a JSON object (written back as JSON) or a string used as it is, so invalid JSON can be recorded.
 * {"error": "<type>"} stands for a call that failed; a case with no entry fails too.
 */
final class RecordedFactJudge implements FactJudge
{
    /** @param  array<string, array<string, mixed>>  $outputs */
    public function __construct(private readonly array $outputs) {}

    public static function fromFile(string $path): self
    {
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($data) || ! is_array($data['outputs'] ?? null)) {
            throw new InvalidArgumentException('The recorded output file is missing or invalid.');
        }

        return new self($data['outputs']);
    }

    public function judge(FactCheckRequest $request): FactJudgeResult
    {
        $entry = $this->outputs[$request->customId] ?? null;
        if (! is_array($entry) || isset($entry['error'])) {
            throw new FactJudgeFailed((string) ($entry['error'] ?? 'not_recorded'), known: true);
        }

        $text = $entry['text'] ?? null;
        if (is_array($text)) {
            $text = json_encode($text, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        return new FactJudgeResult(is_string($text) ? $text : null, (int) ($entry['input_tokens'] ?? 0), (int) ($entry['output_tokens'] ?? 0));
    }
}
