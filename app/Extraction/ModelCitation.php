<?php

namespace App\Extraction;

/** One source the model found in the answer text, with its source type. */
final class ModelCitation
{
    public function __construct(
        public readonly string $url,
        public readonly string $sourceType,
    ) {}
}
