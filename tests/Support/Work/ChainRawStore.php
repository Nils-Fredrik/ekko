<?php

namespace Tests\Support\Work;

use App\Engines\RawResponseStore;
use Carbon\CarbonImmutable;

/** Keeps no raw answers: the chain tests never read them. */
final class ChainRawStore implements RawResponseStore
{
    public function put(int $tenantId, int $runId, CarbonImmutable $collectedAt, array $raw): string
    {
        return "gs://fixture/{$tenantId}/{$runId}.json";
    }

    public function read(string $uri): ?string
    {
        return null;
    }

    public function deleteTenant(int $tenantId): int
    {
        return 0;
    }
}
