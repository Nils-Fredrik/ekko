<?php

namespace App\Enums;

/**
 * The five kinds of action (docs/spec.md 11.3). fact_correction is produced by the fact check (T48) through an
 * action source of its own; it always ranks first within its brand.
 */
enum ActionType: string
{
    case SourceGap = 'source_gap';
    case OwnedContent = 'owned_content';
    case NewCompetitor = 'new_competitor';
    case CrawlerAccess = 'crawler_access';
    case FactCorrection = 'fact_correction';

    /** Sort key for ranking: lower ranks higher. */
    public function rankGroup(): int
    {
        return $this === self::FactCorrection ? 0 : 1;
    }
}
