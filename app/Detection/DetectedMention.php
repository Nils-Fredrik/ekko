<?php

namespace App\Detection;

/** The first occurrence of a known brand or competitor in an answer. */
final class DetectedMention
{
    /**
     * @param  'brand'|'competitor'  $entityType
     * @param  int  $firstCharOffset  code points from the start of the original answer text
     * @param  int|null  $listPosition  1-based number of the list item of the first occurrence inside a list
     */
    public function __construct(
        public readonly string $entityType,
        public readonly int $entityId,
        public readonly string $surfaceName,
        public readonly int $firstCharOffset,
        public readonly ?int $listPosition,
    ) {}
}
