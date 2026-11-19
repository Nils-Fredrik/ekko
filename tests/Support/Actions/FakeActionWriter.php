<?php

namespace Tests\Support\Actions;

use App\ActionEngine\Writing\ActionTextRequest;
use App\ActionEngine\Writing\ActionWriter;
use App\ActionEngine\Writing\ActionWriterFailed;
use App\ActionEngine\Writing\ActionWriterResult;

/**
 * Stands in for Claude Sonnet 5.5: no test makes a paid call. Replies with $reply, or throws $failure.
 */
final class FakeActionWriter implements ActionWriter
{
    /** @var list<ActionTextRequest> */
    public array $requests = [];

    public ?ActionWriterFailed $failure = null;

    public function __construct(public ?string $reply = null, public int $inputTokens = 1200, public int $outputTokens = 300) {}

    public function write(ActionTextRequest $request): ActionWriterResult
    {
        $this->requests[] = $request;
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new ActionWriterResult($this->reply, $this->inputTokens, $this->outputTokens, $this->reply === null ? 'refusal' : null);
    }

    public static function json(string $title, string $description, array $steps): string
    {
        return json_encode(['title' => $title, 'description' => $description, 'steps' => $steps], JSON_THROW_ON_ERROR);
    }
}
