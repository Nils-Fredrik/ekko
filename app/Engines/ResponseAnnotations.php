<?php

namespace App\Engines;

use App\Detection\UrlNormalizer;
use App\Models\Response;

final class ResponseAnnotations
{
    public function __construct(private UrlNormalizer $urls) {}

    public function store(Response $response, EngineResult $result): void
    {
        $seen = [];
        foreach ($result->citations as $index => $citation) {
            $normalized = $this->urls->normalize($citation['url']);
            if ($normalized === null || isset($seen[$normalized['url']])) {
                continue;
            }
            $seen[$normalized['url']] = true;
            $response->citations()->create([
                'url' => $citation['url'], 'url_normalized' => $normalized['url'],
                'domain' => $normalized['domain'], 'ownership' => 'third_party',
                'rank_in_answer' => $citation['rank'] ?? $index + 1, 'from_annotation' => true,
            ]);
        }
    }
}
