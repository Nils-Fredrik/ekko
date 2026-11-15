<?php

namespace App\Detection;

use App\Models\Citation;
use App\Models\Mention;
use App\Models\Response;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantMismatchException;
use Illuminate\Support\Facades\DB;

/**
 * First stage of extraction (docs/spec.md 8.1). Runs synchronously in the worker right after collection,
 * inside the tenant context of the worker job, and is idempotent: it replaces the response's earlier
 * deterministic mentions and re-classifies its citations. Mentions from the model stage are never touched.
 */
final class DeterministicDetector
{
    public function __construct(
        private readonly EntityMatcher $matcher = new EntityMatcher,
        private readonly UrlNormalizer $urls = new UrlNormalizer,
    ) {}

    /**
     * @return array{mentions: int, citations: int} number of mentions written and citations classified
     */
    public function detect(Response $response, ?DetectionCatalog $catalog = null): array
    {
        $contextTenantId = app(TenantContext::class)->require();
        $responseTenantId = (int) $response->getAttribute('tenant_id');
        if ($responseTenantId !== $contextTenantId) {
            throw TenantMismatchException::forModel(Response::class, $responseTenantId, $contextTenantId);
        }

        $catalog ??= DetectionCatalog::load();
        $detected = $this->matcher->detect((string) $response->answer_text, $catalog->aliases);

        return DB::transaction(function () use ($response, $catalog, $detected): array {
            Mention::query()->where('response_id', $response->id)->where('detection', 'deterministic')->delete();

            foreach ($detected as $mention) {
                Mention::query()->create([
                    'response_id' => $response->id,
                    'entity_type' => $mention->entityType,
                    'entity_id' => $mention->entityId,
                    'surface_name' => $mention->surfaceName,
                    'first_char_offset' => $mention->firstCharOffset,
                    'list_position' => $mention->listPosition,
                    'is_recommended' => false,
                    'detection' => 'deterministic',
                ]);
            }

            return ['mentions' => count($detected), 'citations' => $this->classifyCitations($response, $catalog)];
        });
    }

    private function classifyCitations(Response $response, DetectionCatalog $catalog): int
    {
        $classified = 0;

        foreach (Citation::query()->where('response_id', $response->id)->orderBy('id')->get() as $citation) {
            $normalized = $this->urls->normalize($citation->url);
            if ($normalized === null) {
                continue;
            }

            $ownership = $catalog->domains->classify($normalized['domain']);
            $citation->update([
                'url_normalized' => $normalized['url'],
                'domain' => $normalized['domain'],
                'ownership' => $ownership['ownership'],
                'competitor_id' => $ownership['competitor_id'],
            ]);
            $classified++;
        }

        return $classified;
    }
}
