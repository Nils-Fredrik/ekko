<?php

namespace App\Engines;

use SensitiveParameter;

final readonly class EngineRequest
{
    public function __construct(
        public string $promptText,
        public string $country,
        public string $language,
        public ?string $city,
        public string $runId,
        #[SensitiveParameter] public ?string $apiKey = null,
    ) {}
}
