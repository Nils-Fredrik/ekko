<?php

namespace App\Onboarding\Model;

use Anthropic\Client;
use App\Extraction\AnthropicExtractionClient;
use Psr\Http\Client\ClientInterface;
use Throwable;

/**
 * Wizard suggestions through the Anthropic API with the official SDK (docs/spec.md 13, ADR 0018), like
 * {@see AnthropicExtractionClient}: Ekko's own workspace key, structured output
 * (output_config.format with a JSON schema), no prefill. Claude Sonnet 5.5 rejects thinking: disabled,
 * budget_tokens, sampling parameters and a forced tool_choice, so none of them is sent.
 */
final class AnthropicOnboardingClient implements OnboardingModelClient
{
    public function __construct(private readonly Client $client) {}

    public static function fromConfig(?ClientInterface $transporter = null): self
    {
        $key = config('services.anthropic.key');
        if (! is_string($key) || $key === '') {
            throw OnboardingModelFailed::notConfigured();
        }

        return new self(new Client(
            apiKey: $key,
            requestOptions: $transporter === null ? null : ['transporter' => $transporter],
        ));
    }

    public function suggest(OnboardingRequest $request): OnboardingModelResult
    {
        /** @var array{model: string, max_tokens: int, effort: string} $config */
        $config = config('ekko.anthropic.onboarding');

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
        } catch (Throwable $e) {
            // The provider's message can echo the request, which holds the customer's site content.
            throw OnboardingModelFailed::unavailable($e::class);
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        $failure = in_array($message->stopReason, ['refusal', 'max_tokens'], true) ? $message->stopReason : null;

        return new OnboardingModelResult(
            $failure === null ? $text : null,
            $message->usage->inputTokens,
            $message->usage->outputTokens,
            $failure,
        );
    }
}
