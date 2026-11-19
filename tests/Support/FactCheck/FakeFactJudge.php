<?php

namespace Tests\Support\FactCheck;

use App\FactCheck\FactCheckRequest;
use App\FactCheck\Judge\FactJudge;
use App\FactCheck\Judge\FactJudgeFailed;
use App\FactCheck\Judge\FactJudgeResult;
use App\FactCheck\Judge\RecordedFactJudge;
use Closure;

/**
 * Stands in for the fact check model: no test makes a paid call. Answers from a closure that maps a custom id to a
 * recorded entry, keeps what was sent, and throws $failure when it is set.
 */
final class FakeFactJudge implements FactJudge
{
    /** @var list<FactCheckRequest> */
    public array $requests = [];

    public ?FactJudgeFailed $failure = null;

    /** @param  Closure(string): array<string, mixed>  $entryFor */
    public function __construct(private readonly Closure $entryFor) {}

    public function judge(FactCheckRequest $request): FactJudgeResult
    {
        $this->requests[] = $request;
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return (new RecordedFactJudge([$request->customId => ($this->entryFor)($request->customId)]))->judge($request);
    }

    /**
     * @param  list<array<string, mixed>>  $claims
     * @return array<string, mixed>
     */
    public static function entry(array $claims, int $in = 900, int $out = 80): array
    {
        return ['text' => ['claims' => $claims], 'input_tokens' => $in, 'output_tokens' => $out];
    }
}
