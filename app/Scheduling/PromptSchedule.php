<?php

namespace App\Scheduling;

use Carbon\CarbonInterface;
use InvalidArgumentException;

/** Deterministic cadence in the tenant's local calendar (spec 7.1). */
final class PromptSchedule
{
    private const RANK = ['off' => 0, 'monthly' => 1, 'every_21_days' => 2, 'every_14_days' => 3, 'weekly' => 4, 'daily' => 5];

    /** @return list<string> Options in display order, fastest first. */
    public static function frequencies(string $maximum = 'daily'): array
    {
        $allowed = array_slice(array_keys(self::RANK), 0, self::RANK[$maximum] + 1);

        return array_reverse($allowed);
    }

    public static function allows(string $frequency, string $maximum): bool
    {
        return isset(self::RANK[$frequency], self::RANK[$maximum]) && self::RANK[$frequency] <= self::RANK[$maximum];
    }

    public static function capped(string $frequency, string $maximum): string
    {
        if (! isset(self::RANK[$frequency], self::RANK[$maximum])) {
            throw new InvalidArgumentException('Unsupported prompt frequency.');
        }

        return self::RANK[$frequency] <= self::RANK[$maximum] ? $frequency : $maximum;
    }

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
            'every_14_days' => self::intervalDue($hash, $localDay, 14),
            'every_21_days' => self::intervalDue($hash, $localDay, 21),
            'monthly' => $hash % 28 + 1 === $localDay->day,
            'off' => false,
            default => throw new InvalidArgumentException('Unsupported prompt frequency.'),
        };
    }

    private static function intervalDue(int $hash, CarbonInterface $localDay, int $days): bool
    {
        // Offset the instant to its local civil date before counting days from Sunday 1970-01-04.
        $civilDay = (int) floor(($localDay->getTimestamp() + $localDay->getOffset()) / 86400);

        $daysSinceAnchor = $civilDay - 3;
        $promptOffset = $hash % $days;

        return ($daysSinceAnchor - $promptOffset) % $days === 0;
    }
}
