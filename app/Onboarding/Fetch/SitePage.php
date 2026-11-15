<?php

namespace App\Onboarding\Fetch;

/** One fetched page, reduced to what goes to the model. */
final readonly class SitePage
{
    public function __construct(
        public string $url,
        public string $title,
        public string $description,
        public string $text,
    ) {}
}
