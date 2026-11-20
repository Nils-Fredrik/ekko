<?php

namespace App\Insights;

use App\ActionEngine\Writing\ActionTextRequest;
use App\ActionEngine\Writing\ActionWriterFailed;
use App\ActionEngine\Writing\ActionWriterResult;

/**
 * Writes the insight text with Claude Sonnet 5.5 (docs/spec.md 14.2, ADR 0038). Bound to the Anthropic writer with
 * the `insight` settings; tests bind a fake, so no test makes a paid call.
 */
interface InsightTextWriter
{
    /**
     * @throws ActionWriterFailed
     */
    public function write(ActionTextRequest $request): ActionWriterResult;
}
