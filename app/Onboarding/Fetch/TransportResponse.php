<?php

namespace App\Onboarding\Fetch;

final readonly class TransportResponse
{
    /** @param  bool  $truncated  the body was longer than the limit; $body then holds the first limit + 1 bytes */
    public function __construct(
        public int $status,
        public ?string $contentType,
        public ?string $location,
        public string $body,
        public bool $truncated = false,
        public ?int $contentLength = null,
    ) {}
}
