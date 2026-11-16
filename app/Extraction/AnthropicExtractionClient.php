<?php

namespace App\Extraction;

use Anthropic\Client;
use Anthropic\Messages\Batches\MessageBatchErroredResult;
use Anthropic\Messages\Batches\MessageBatchSucceededResult;
use Anthropic\Messages\Message;
use App\AnthropicBudget\Account;
use Psr\Http\Client\ClientInterface;

/**
 * Model-based extraction through the Anthropic API (docs/spec.md 8.2, ADR 0011): Message Batches for the
 * bulk, the Messages API for the single retry, both with the same parameters. The answer is requested as
 * structured output (output_config.format with a JSON schema), so the text of the reply is the JSON.
 *
 * Built with Ekko's own workspace key from services.anthropic.key. The SDK client accepts a PSR-18
 * transporter, which is how tests run this class against canned HTTP responses without a network.
 */
final class AnthropicExtractionClient implements ExtractionClient
{
    public function __construct(private readonly Client $client) {}

    public static function fromConfig(?ClientInterface $transporter = null): self
    {
        $key = config('services.anthropic.key');
        if (! is_string($key) || $key === '') {
            throw ExtractionNotConfiguredException::missingApiKey();
        }

        return new self(new Client(
            apiKey: $key,
            requestOptions: $transporter === null ? null : ['transporter' => $transporter],
        ));
    }

    public function createBatch(array $requests): string
    {
        $batch = $this->client->messages->batches->create(requests: array_map(
            fn (ExtractionRequest $request): array => ['customID' => $request->customId, 'params' => $this->params($request)],
            $requests,
        ));

        return $batch->id;
    }

    public function batchEnded(string $batchId): bool
    {
        return $this->client->messages->batches->retrieve($batchId)->processingStatus === 'ended';
    }

    public function batchResults(string $batchId): iterable
    {
        foreach ($this->client->messages->batches->resultsStream($batchId) as $row) {
            $result = $row->result;

            if ($result instanceof MessageBatchSucceededResult) {
                yield $this->outcome($row->customID, $result->message, true);
            } else {
                if ($result instanceof MessageBatchErroredResult && str_contains(strtolower($result->error->error->message), 'credit balance is too low')) {
                    Account::independent()->markExhausted();
                }
                $type = $result instanceof MessageBatchErroredResult ? $result->error->error->type : $result->type;
                yield new ExtractionOutcome($row->customID, false, null, 0, 0, true, $type);
            }
        }
    }

    public function message(ExtractionRequest $request): ExtractionOutcome
    {
        $message = $this->client->messages->create(...$this->params($request));

        return $this->outcome($request->customId, $message, false);
    }

    /** @return array<string, mixed> */
    private function params(ExtractionRequest $request): array
    {
        /** @var array{model: string, max_tokens: int, effort: string} $config */
        $config = config('ekko.anthropic.extraction');

        return [
            'model' => $config['model'],
            'maxTokens' => $config['max_tokens'],
            'system' => $request->system,
            'messages' => [['role' => 'user', 'content' => $request->user]],
            'outputConfig' => [
                'effort' => $config['effort'],
                'format' => ['type' => 'json_schema', 'schema' => ExtractionSchema::schema()],
            ],
        ];
    }

    private function outcome(string $customId, Message $message, bool $viaBatch): ExtractionOutcome
    {
        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        // A refusal and an answer cut off at max_tokens carry no usable JSON, but their tokens were billed.
        $failure = in_array($message->stopReason, ['refusal', 'max_tokens'], true) ? $message->stopReason : null;

        return new ExtractionOutcome(
            $customId,
            $failure === null,
            $failure === null ? $text : null,
            $message->usage->inputTokens,
            $message->usage->outputTokens,
            $viaBatch,
            $failure,
        );
    }
}
