<?php

namespace App\FactCheck\Judge;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIStatusException;
use App\FactCheck\FactCheckRequest;
use Psr\Http\Client\ClientInterface;
use Throwable;

/**
 * The fact check through the Anthropic API with the official SDK, like the action wording: Ekko's own workspace key,
 * structured output with a JSON schema, no retries (a retried call could be billed twice), no sampling parameters.
 * The model id comes from config('ekko.anthropic.factcheck.model'), so it can be switched without a code change.
 */
final class AnthropicFactJudge implements FactJudge
{
    public function __construct(private readonly Client $client) {}

    public static function configured(): bool
    {
        $key = config('services.anthropic.key');

        return is_string($key) && trim($key) !== '';
    }

    public static function fromConfig(?ClientInterface $transporter = null): self
    {
        $key = config('services.anthropic.key');
        if (! is_string($key) || trim($key) === '') {
            throw new FactJudgeFailed('not_configured', known: true, accountWide: true);
        }

        return new self(new Client(
            apiKey: $key,
            requestOptions: ['maxRetries' => 0, 'timeout' => (float) config('ekko.anthropic.factcheck.timeout_seconds'), ...($transporter === null ? [] : ['transporter' => $transporter])],
        ));
    }

    public function judge(FactCheckRequest $request): FactJudgeResult
    {
        /** @var array{model: string, max_tokens: int, effort: string} $config */
        $config = config('ekko.anthropic.factcheck');

        try {
            $message = $this->client->messages->create(
                model: $config['model'],
                maxTokens: $config['max_tokens'],
                system: $request->system,
                messages: [['role' => 'user', 'content' => $request->user]],
                outputConfig: [
                    'effort' => $config['effort'],
                    'format' => ['type' => 'json_schema', 'schema' => $request->schema],
                ],
            );
        } catch (APIStatusException $e) {
            // The provider's message can echo the request; only the class and the credit signal are kept.
            $status = $e->status ?? 0;
            $known = $status >= 400 && $status < 500 && $status !== 408;
            $credit = str_contains(strtolower($e->getAPIMessage() ?? ''), 'credit balance is too low');
            // An error about the request (400, 413, 422) is this answer's; the others are the account's or the service's.
            $accountWide = $credit || ! in_array($status, [400, 413, 422], true);
            throw new FactJudgeFailed($e::class, $known, $credit, $accountWide);
        } catch (Throwable $e) {
            throw new FactJudgeFailed($e::class);
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }
        $failure = in_array($message->stopReason, ['refusal', 'max_tokens'], true) ? $message->stopReason : null;

        return new FactJudgeResult($failure === null ? $text : null, $message->usage->inputTokens, $message->usage->outputTokens, $failure);
    }
}
