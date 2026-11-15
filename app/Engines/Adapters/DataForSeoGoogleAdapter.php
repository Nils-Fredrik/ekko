<?php

namespace App\Engines\Adapters;

use App\Engines\EngineCallFailed;
use App\Engines\EngineResult;

abstract class DataForSeoGoogleAdapter extends DataForSeoQueuedAdapter
{
    protected const ?string LOCATIONS_PATH = '/serp/google/locations';

    /**
     * @param  array<array-key, mixed>  $result
     * @param  array<array-key, mixed>  $raw
     */
    final protected function parseResult(array $result, array $raw): EngineResult
    {
        $items = $result['items'] ?? null;

        if (! array_key_exists('items', $result) || ($items !== null && ! is_array($items))) {
            throw new EngineCallFailed('dataforseo_invalid_response');
        }

        $answers = [];
        $references = [];

        foreach ($items ?? [] as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'ai_overview') {
                continue;
            }

            $answers[] = $this->overviewText($item);
            array_push($references, ...$this->overviewReferences($item));
        }

        return new EngineResult(
            answerText: implode("\n\n", array_filter($answers, fn (string $text): bool => $text !== '')),
            modelIdReported: null,
            citations: array_values($this->appendCitations($references)),
            inputTokens: 0,
            outputTokens: 0,
            searchCalls: 0,
            raw: $raw,
        );
    }

    /** @param array<array-key, mixed> $node */
    private function overviewText(array $node): string
    {
        $markdown = $node['markdown'] ?? null;

        if (is_string($markdown) && trim($markdown) !== '') {
            return $markdown;
        }

        $parts = [];
        $text = $node['text'] ?? null;

        if (is_string($text) && trim($text) !== '') {
            $parts[] = $text;
        }

        foreach ($this->overviewChildren($node) as $child) {
            if (($child['type'] ?? null) === 'ai_overview_paid') {
                continue;
            }

            $text = $this->overviewText($child);

            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @return list<mixed>
     */
    private function overviewReferences(array $node): array
    {
        $references = [];

        foreach ($this->overviewChildren($node) as $child) {
            array_push($references, ...$this->overviewReferences($child));
        }

        $sources = $node['references'] ?? null;

        return is_array($sources) ? array_merge($references, array_values($sources)) : $references;
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @return iterable<array<array-key, mixed>>
     */
    private function overviewChildren(array $node): iterable
    {
        foreach (['items', 'components'] as $field) {
            $children = $node[$field] ?? null;

            foreach (is_array($children) ? $children : [] as $child) {
                if (is_array($child)) {
                    yield $child;
                }
            }
        }
    }
}
