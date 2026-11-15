<?php

namespace App\Onboarding\Fetch;

final readonly class FetchedPage
{
    public function __construct(
        /** The address after redirects. */
        public string $url,
        public string $contentType,
        public string $body,
    ) {}
}
