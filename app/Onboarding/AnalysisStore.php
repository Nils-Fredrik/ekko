<?php

namespace App\Onboarding;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Keeps analyses in the cache, one key per tenant and analysis (ADR 0018). The tenant is part of the key,
 * so an id from another tenant finds nothing, and find() checks it once more on the stored value.
 */
final class AnalysisStore
{
    /** @param  array<string, mixed>  $input */
    public function create(int $tenantId, int $userId, string $kind, array $input): Analysis
    {
        $analysis = new Analysis((string) Str::uuid(), $tenantId, $userId, $kind, $input);
        $this->save($analysis);

        return $analysis;
    }

    public function find(int $tenantId, string $id): ?Analysis
    {
        $data = Cache::get($this->key($tenantId, $id));
        if (! is_array($data) || ($data['tenant_id'] ?? null) !== $tenantId) {
            return null;
        }

        return Analysis::fromArray($data);
    }

    public function save(Analysis $analysis): void
    {
        Cache::put(
            $this->key($analysis->tenantId, $analysis->id),
            $analysis->toArray(),
            now()->addHours((int) config('ekko.wizard.proposal_ttl_hours')),
        );
    }

    private function key(int $tenantId, string $id): string
    {
        return "onboarding:analysis:{$tenantId}:{$id}";
    }
}
