<?php

namespace App\Scheduling;

use Carbon\CarbonInterface;
use InvalidArgumentException;

/** Deterministic cadence in the tenant's local calendar (spec 7.1). */
final class PromptSchedule
{
    public static function due(int $promptId, string $frequency, CarbonInterface $localDay): bool
    {
        if ($promptId < 1) {
            throw new InvalidArgumentException('Prompt IDs must be positive.');
        }
        $hash = (int) sprintf('%u', crc32((string) $promptId));

        return match ($frequency) {
            'daily' => true,
            // Carbon's Sunday=0 numbering is the explicit convention for crc32(id) % 7.
            'weekly' => $hash % 7 === $localDay->dayOfWeek,
            'monthly' => $hash % 28 + 1 === $localDay->day,
            'off' => false,
            default => throw new InvalidArgumentException('Unsupported prompt frequency.'),
        };
    }
}
