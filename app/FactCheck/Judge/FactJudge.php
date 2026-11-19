<?php

namespace App\FactCheck\Judge;

use App\FactCheck\FactCheckRequest;

/**
 * Judges the claims about a brand in one answer against its facts (docs/spec.md 11.6, ADR 0036). Bound to
 * AnthropicFactJudge (Claude Sonnet, model id from ekko.anthropic.factcheck); tests bind a fake, so no test makes a
 * paid call.
 */
interface FactJudge
{
    /**
     * @throws FactJudgeFailed
     */
    public function judge(FactCheckRequest $request): FactJudgeResult;
}
