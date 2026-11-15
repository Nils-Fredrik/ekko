<?php

namespace App\Engines\Adapters;

use App\Engines\EngineCallFailed;
use App\Engines\EngineResult;

abstract class DataForSeoScraperAdapter extends DataForSeoQueuedAdapter
{
    /** @param array<array-key, mixed> $result
     * @param  array<array-key, mixed>  $raw
     */
    final protected function parseResult(array $result, array $raw): EngineResult
    {
        $text = $result['markdown'] ?? null;

        if (! is_string($text) || trim($text) === '') {
            throw new EngineCallFailed('dataforseo_empty_answer');
        }

        $citations = [];

        foreach (is_array($result['items'] ?? null) ? $result['items'] : [] as $item) {
            $sources = data_get($item, 'sources');
            $citations = $this->appendCitations(is_array($sources) ? $sources : [], $citations);
        }

        $sources = $result['sources'] ?? null;
        $citations = $this->appendCitations(is_array($sources) ? $sources : [], $citations);
        $model = $result['model'] ?? null;

        return new EngineResult(
            answerText: $text,
            modelIdReported: is_string($model) && $model !== '' ? $model : null,
            citations: array_values($citations),
            inputTokens: 0,
            outputTokens: 0,
            searchCalls: 0,
            raw: $raw,
        );
    }
}
