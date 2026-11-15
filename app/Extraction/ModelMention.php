<?php

namespace App\Extraction;

/** One mention as the model reported it, after validation. */
final class ModelMention
{
    /**
     * @param  'brand'|'competitor'|'unknown'  $entityType
     */
    public function __construct(
        public readonly string $surfaceName,
        public readonly string $entityType,
        public readonly ?int $entityId,
        public readonly ?int $listPosition,
        public readonly bool $isRecommended,
        public readonly float $sentiment,
        public readonly string $evidence,
    ) {}

    public function entityKey(): ?string
    {
        return $this->entityId === null ? null : $this->entityType.':'.$this->entityId;
    }
}
