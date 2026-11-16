<?php

namespace App\Onboarding\Model;

use RuntimeException;

/** The provider call failed (network, authentication, rate limit, overload). Carries no provider message. */
final class OnboardingModelFailed extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('The wizard model needs ANTHROPIC_API_KEY (services.anthropic.key).');
    }

    public static function unavailable(?string $errorClass = null): self
    {
        return new self('The onboarding model call failed'.($errorClass === null ? '.' : " ({$errorClass})."));
    }
}
