<?php

namespace App\ActionEngine;

/**
 * What a source found for one brand. evaluatedMarkets are the scopes the source could judge (enough data): a
 * market id, or null for a brand-level source such as crawler_access. Open actions of the source's type in an
 * evaluated scope that are not among the candidates count as not supported by this generation; actions in a scope
 * the source could not judge are left alone.
 */
final readonly class SourceOutcome
{
    /**
     * @param  list<int|null>  $evaluatedMarkets
     * @param  list<ActionCandidate>  $candidates
     */
    public function __construct(
        public array $evaluatedMarkets,
        public array $candidates,
    ) {}
}
