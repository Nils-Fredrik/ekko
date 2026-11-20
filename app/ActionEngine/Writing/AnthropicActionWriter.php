<?php

namespace App\ActionEngine\Writing;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIStatusException;
use App\Insights\InsightTextWriter;
use App\Onboarding\Model\AnthropicOnboardingClient;
use Psr\Http\Client\ClientInterface;
use Throwable;

/**
 * Action wording through the Anthropic API with the official SDK, like {@see AnthropicOnboardingClient}:
 * Ekko's own workspace key, structured output with a JSON schema, no retries (a retried call could be billed
 * twice), no sampling parameters. The same writer words the dashboard insight text (ADR 0038): `$settings` names the
 * block of config('ekko.anthropic') with the model, token limit and timeout.
 */
final class AnthropicActionWriter implements ActionWriter, InsightTextWriter
{
    public function __construct(private readonly Client $client, private readonly string $settings = 'actions') {}

    public static function configured(): bool
    {
        $key = config('services.anthropic.key');

        return is_string($key) && trim($key) !== '';
    }

    public static function fromConfig(?ClientInterface $transporter = null, string $settings = 'actions'): self
    {
        $key = config('services.anthropic.key');
        if (! is_string($key) || trim($key) === '') {
            throw new ActionWriterFailed('not_configured', known: true);
        }

        return new self(new Client(
            apiKey: $key,
            requestOptions: ['maxRetries' => 0, 'timeout' => (float) config("ekko.anthropic.{$settings}.timeout_seconds"), ...($transporter === null ? [] : ['transporter' => $transporter])],
        ), $settings);
    }

    public function write(ActionTextRequest $request): ActionWriterResult
    {
        /** @var array{model: string, max_tokens: int, effort: string} $config */
        $config = config("ekko.anthropic.{$this->settings}");

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
            throw new ActionWriterFailed($e::class, $known, str_contains(strtolower($e->getAPIMessage() ?? ''), 'credit balance is too low'));
        } catch (Throwable $e) {
            throw new ActionWriterFailed($e::class);
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }
        $failure = in_array($message->stopReason, ['refusal', 'max_tokens'], true) ? $message->stopReason : null;

        return new ActionWriterResult($failure === null ? $text : null, $message->usage->inputTokens, $message->usage->outputTokens, $failure);
    }
}
