<?php

namespace App\Onboarding\Model;

/** What the provider returned: the model's JSON text, or the reason there is none. Tokens are billed either way. */
final readonly class OnboardingModelResult
{
    public function __construct(
        public ?string $text,
        public int $inputTokens,
        public int $outputTokens,
        /** refusal or max_tokens when the reply has no usable text. */
        public ?string $failure = null,
    ) {}
}
