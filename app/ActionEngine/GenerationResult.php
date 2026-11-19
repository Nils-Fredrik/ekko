<?php

namespace App\ActionEngine;

/**
 * What one generation of a brand did. toWord: new system actions that still have the template text.
 */
final readonly class GenerationResult
{
    /**
     * @param  list<int>  $toWord
     */
    public function __construct(
        public int $created,
        public int $refreshed,
        public int $reopened,
        public int $expired,
        public array $toWord,
    ) {}
}
