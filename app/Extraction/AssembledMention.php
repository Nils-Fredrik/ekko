<?php

namespace App\Extraction;

/** One mention as stored after the stages are combined (docs/spec.md 8.2). */
final class AssembledMention
{
    /**
     * @param  'brand'|'competitor'|'unknown'  $entityType
     * @param  'deterministic'|'llm'  $detection
     */
    public function __construct(
        public readonly string $entityType,
        public readonly ?int $entityId,
        public readonly string $surfaceName,
        public readonly ?int $firstCharOffset,
        public readonly ?int $listPosition,
        public readonly bool $isRecommended,
        public readonly ?float $sentiment,
        public readonly string $detection,
    ) {}
}
