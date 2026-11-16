<?php

namespace App\Engines;

use Carbon\CarbonImmutable;

interface RawResponseStore
{
    /** @param array<array-key, mixed> $raw */
    public function put(int $tenantId, int $runId, CarbonImmutable $collectedAt, array $raw): string;

    /** Deletes every raw answer of the tenant, when the tenant itself is deleted (T39-F6). Returns the count. */
    public function deleteTenant(int $tenantId): int;
}
