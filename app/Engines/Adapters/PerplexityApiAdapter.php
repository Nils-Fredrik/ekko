<?php

namespace App\Engines\Adapters;

use App\Engines\EngineAdapter;
use App\Engines\EngineCallFailed;
use App\Engines\EngineRequest;
use App\Engines\EngineResult;
use App\Models\Engine;
use App\Models\Market;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class PerplexityApiAdapter implements EngineAdapter
{
    public function key(): string
    {
        return 'perplexity_api';
    }

    public function supports(Market $market): bool
    {
        return preg_match('/^[A-Z]{2}$/D', $market->country) === 1
            && preg_match('/^[a-z]{2,8}(?:-[a-z0-9]{1,8})*$/iD', $market->language) === 1;
    }

    public function collect(EngineRequest $request): EngineResult
    {
        $apiKey = trim($request->apiKey ?? config()->string('engines.perplexity_api.api_key'));

        if ($apiKey === '') {
            throw new EngineCallFailed('perplexity_missing_api_key');
        }

        $engine = Engine::query()->find($this->key());
        $model = trim($engine->model_id ?? '');
        $model = $model !== '' ? $model : config()->string('engines.perplexity_api.model');
        $location = ['country' => $request->country];

        if ($request->city !== null && $request->city !== '') {
            $location['city'] = $request->city;
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->withoutRedirecting()
                ->timeout(120)
                ->retry([250, 500], when: self::shouldRetry(...), throw: false)
                ->post('https://api.perplexity.ai/v1/agent', [
                    'model' => $model,
                    'input' => $request->promptText."\n\nRespond in the language identified by the BCP 47 tag {$request->language}.",
                    'store' => false,
                    'tools' => [['type' => 'web_search', 'user_location' => $location]],
                    'tool_choice' => 'required',
                ]);
        } catch (ConnectionException) {
            throw new EngineCallFailed('perplexity_connection_failed');
        }

        if (! $response->successful()) {
            throw new EngineCallFailed('perplexity_http_'.$response->status());
        }

        $raw = $response->json();

        if (! is_array($raw) || ($raw['status'] ?? null) !== 'completed') {
            throw new EngineCallFailed('perplexity_invalid_response');
        }

        return $this->parseResult($raw);
    }

    private static function shouldRetry(Throwable $failure): bool
    {
        return $failure instanceof RequestException
            && ($failure->response->status() === 429 || $failure->response->serverError());
    }

    /** @param array<array-key, mixed> $raw */
    private function parseResult(array $raw): EngineResult
    {
        $output = $raw['output'] ?? null;
        $inputTokens = data_get($raw, 'usage.input_tokens');
        $outputTokens = data_get($raw, 'usage.output_tokens');
        $searchCalls = data_get($raw, 'usage.tool_calls_details.search_web.invocation');

        if (! is_array($output) || ! is_int($inputTokens) || ! is_int($outputTokens)
            || min($inputTokens, $outputTokens) < 0 || ($searchCalls !== null && (! is_int($searchCalls) || $searchCalls < 0))) {
            throw new EngineCallFailed('perplexity_invalid_response');
        }

        $parts = [];
        $citations = [];
        $searchResultItems = 0;

        foreach ($output as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (($item['type'] ?? null) === 'search_results') {
                $searchResultItems++;
                $this->addCitations($citations, $item['results'] ?? []);

                continue;
            }

            if (($item['type'] ?? null) !== 'message' || ($item['role'] ?? null) !== 'assistant'
                || ! is_array($item['content'] ?? null)) {
                continue;
            }

            foreach ($item['content'] as $part) {
                if (! is_array($part) || ($part['type'] ?? null) !== 'output_text' || ! is_string($part['text'] ?? null)) {
                    continue;
                }

                $parts[] = $part['text'];
                $this->addCitations($citations, $part['annotations'] ?? [], annotationsOnly: true);
            }
        }

        $text = implode("\n\n", $parts);

        if (trim($text) === '') {
            throw new EngineCallFailed('perplexity_invalid_response');
        }

        return new EngineResult(
            answerText: $text,
            modelIdReported: is_string($raw['model'] ?? null) ? $raw['model'] : null,
            citations: array_values($citations),
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            searchCalls: $searchCalls ?? $searchResultItems,
            raw: $raw,
        );
    }

    /** @param array<string, array{url: string, title: string|null, rank: int, from_annotation: true}> $citations */
    private function addCitations(array &$citations, mixed $sources, bool $annotationsOnly = false): void
    {
        if (! is_array($sources)) {
            return;
        }

        foreach ($sources as $source) {
            if (! is_array($source) || ($annotationsOnly && ($source['type'] ?? null) !== 'url_citation')) {
                continue;
            }

            $url = $source['url'] ?? null;

            if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false
                || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                continue;
            }

            $title = is_string($source['title'] ?? null) && trim($source['title']) !== '' ? $source['title'] : null;

            if (isset($citations[$url])) {
                $citations[$url]['title'] ??= $title;

                continue;
            }

            $citations[$url] = [
                'url' => $url,
                'title' => $title,
                'rank' => count($citations) + 1,
                'from_annotation' => true,
            ];
        }
    }
}
