<?php

namespace App\Onboarding\Model;

/** A prompt for the wizard model. Holds untrusted site content, so it is never logged. */
final readonly class OnboardingRequest
{
    /** @param  array<string, mixed>  $schema  the JSON schema the reply must follow */
    public function __construct(
        public string $system,
        public string $user,
        public array $schema,
    ) {}
}
