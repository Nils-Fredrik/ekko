<?php

namespace App\FactCheck;

/**
 * One claim about the brand as the model reported it (docs/spec.md 11.6): the fact it bears on, the claim in
 * short, an exact quote from the answer, the verdict and, for a contradicted claim, the source URLs the answer
 * bases it on. Validated and cleaned by {@see ClaimAssembler} before it is stored.
 */
final class ModelClaim
{
    /**
     * @param  list<string>  $sourceUrls
     */
    public function __construct(
        public readonly ?int $factId,
        public readonly string $text,
        public readonly string $quote,
        public readonly string $verdict,
        public readonly array $sourceUrls = [],
    ) {}
}
