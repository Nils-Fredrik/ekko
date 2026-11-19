<?php

namespace App\FactCheck\Judge;

use App\FactCheck\FactCheckRequest;

/**
 * Wraps a judge and keeps what the model returned, in the format {@see RecordedFactJudge} replays. The evaluation
 * command uses it for `--record`, so one run with a real key can be replayed offline. The recording holds model output
 * for the synthetic gold set only, never real answers.
 */
final class RecordingFactJudge implements FactJudge
{
    /** @var array<string, array<string, mixed>> */
    private array $outputs = [];

    public function __construct(private readonly FactJudge $inner) {}

    public function judge(FactCheckRequest $request): FactJudgeResult
    {
        try {
            $result = $this->inner->judge($request);
        } catch (FactJudgeFailed $failure) {
            $this->outputs[$request->customId] = ['error' => $failure->getMessage()];

            throw $failure;
        }

        $decoded = $result->text === null ? null : json_decode($result->text, true);
        $this->outputs[$request->customId] = ['text' => is_array($decoded) ? $decoded : $result->text, 'input_tokens' => $result->inputTokens, 'output_tokens' => $result->outputTokens];

        return $result;
    }

    public function writeTo(string $path): void
    {
        ksort($this->outputs);
        file_put_contents($path, json_encode(['outputs' => $this->outputs], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }
}
