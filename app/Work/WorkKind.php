<?php

namespace App\Work;

/**
 * The kinds of event-driven processing work (docs/spec.md 7.1, ADR 0032), each with the scopes it accepts.
 * A scope holds internal identifiers only, never customer data:
 *
 * - extraction: "tenant" (everything collected in the tenant) or "run_batch:<id>" (one collection group)
 * - aggregation: a tenant-local day, Y-m-d
 * - usage_rollup: a UTC month, Y-m
 */
enum WorkKind: string
{
    case Extraction = 'extraction';
    case Aggregation = 'aggregation';
    case UsageRollup = 'usage_rollup';

    public function accepts(string $scope): bool
    {
        return match ($this) {
            self::Extraction => $scope === WorkScope::TENANT || preg_match('/^run_batch:[1-9][0-9]{0,14}$/', $scope) === 1,
            self::Aggregation => WorkScope::isDate($scope, 'Y-m-d'),
            self::UsageRollup => WorkScope::isDate($scope, 'Y-m'),
        };
    }
}
