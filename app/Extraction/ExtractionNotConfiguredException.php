<?php

namespace App\Extraction;

use RuntimeException;

final class ExtractionNotConfiguredException extends RuntimeException
{
    public static function missingApiKey(): self
    {
        return new self('Model-based extraction needs ANTHROPIC_API_KEY (services.anthropic.key).');
    }
}
