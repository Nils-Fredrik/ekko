<?php

namespace App\FactCheck;

use App\Models\Claim;

/**
 * Reads the model's JSON into claims (docs/spec.md 11.6). Null when it is not JSON of the right shape; a claim that
 * is not of the right shape is skipped. What is left still goes through {@see ClaimAssembler}.
 */
final class ClaimParser
{
    private const MAX = 50;

    /** @return list<ModelClaim>|null */
    public static function parse(?string $json): ?array
    {
        $data = $json === null ? null : json_decode($json, true);
        if (! is_array($data) || ! is_array($data['claims'] ?? null) || ! array_is_list($data['claims'])) {
            return null;
        }

        $claims = [];
        foreach (array_slice($data['claims'], 0, self::MAX) as $row) {
            if (! is_array($row) || ! is_int($row['fact_id'] ?? null) || ! is_string($row['text'] ?? null) || ! is_string($row['quote'] ?? null)
                || ! in_array($row['verdict'] ?? null, Claim::VERDICTS, true)) {
                continue;
            }
            $urls = is_array($row['source_urls'] ?? null) ? array_values(array_filter($row['source_urls'], fn ($url): bool => is_string($url) && trim($url) !== '')) : [];
            $claims[] = new ModelClaim($row['fact_id'], $row['text'], $row['quote'], $row['verdict'], array_map(trim(...), $urls));
        }

        return $claims;
    }
}
