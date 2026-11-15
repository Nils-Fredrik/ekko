<?php

namespace App\Detection;

/** One alias of a brand or competitor, as detection sees it. */
final class AliasPattern
{
    public const EXACT_WORD = 'exact_word';

    public const CASE_INSENSITIVE = 'case_insensitive';

    public const REGEX = 'regex';

    /**
     * @param  'brand'|'competitor'  $entityType
     */
    public function __construct(
        public readonly string $entityType,
        public readonly int $entityId,
        public readonly string $alias,
        public readonly string $matchMode,
    ) {}

    public function entityKey(): string
    {
        return $this->entityType.':'.$this->entityId;
    }
}
