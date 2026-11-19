<?php

namespace Tests\Support\Insights;

use App\ActionEngine\Writing\ActionTextRequest;
use App\ActionEngine\Writing\ActionWriterFailed;
use App\ActionEngine\Writing\ActionWriterResult;
use App\Insights\InsightTextWriter;

/**
 * Stands in for Claude Sonnet 5.5: no test makes a paid call. Replies with $reply, or throws $failure.
 */
final class FakeInsightWriter implements InsightTextWriter
{
    /** @var list<ActionTextRequest> */
    public array $requests = [];

    public ?ActionWriterFailed $failure = null;

    public function __construct(public ?string $reply = null, public int $inputTokens = 1500, public int $outputTokens = 120) {}

    public function write(ActionTextRequest $request): ActionWriterResult
    {
        $this->requests[] = $request;
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new ActionWriterResult($this->reply, $this->inputTokens, $this->outputTokens, $this->reply === null ? 'refusal' : null);
    }

    public static function json(string $text): string
    {
        return json_encode(['text' => $text], JSON_THROW_ON_ERROR);
    }
}
