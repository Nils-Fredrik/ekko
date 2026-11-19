<?php

namespace App\ActionEngine;

/**
 * What a source found for one brand. evaluatedMarkets are the scopes the source could judge (enough data): a
 * market id, or null for a brand-level source such as crawler_access. Open actions of the source's type in an
 * evaluated scope that are not among the candidates count as not supported by this generation; actions in a scope
 * the source could not judge are left alone. alsoSupported are findings the data still supports but the source did
 * not turn into candidates (beyond a per-market limit): an existing action for one of them is neither refreshed nor
 * counted as unsupported.
 */
final readonly class SourceOutcome
{
    /**
     * @param  list<int|null>  $evaluatedMarkets
     * @param  list<ActionCandidate>  $candidates
     * @param  list<array{market_id: int|null, subject: string}>  $alsoSupported
     */
    public function __construct(
        public array $evaluatedMarkets,
        public array $candidates,
        public array $alsoSupported = [],
    ) {}
}
