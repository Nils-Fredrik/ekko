<?php

namespace App\Engines\Adapters;

use App\Detection\UrlNormalizer;
use App\Engines\EngineAdapter;
use App\Engines\EngineCallFailed;
use App\Engines\EngineRequest;
use App\Engines\EngineResult;
use App\Models\Engine;
use App\Models\Market;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

final class GeminiApiAdapter implements EngineAdapter
{
    private const GROUNDING_REDIRECT_HOST = 'vertexaisearch.cloud.google.com';

    public function key(): string
    {
        return 'gemini_api';
    }

    public function supports(Market $market): bool
    {
        return preg_match('/^[A-Z]{2}$/D', $market->country) === 1
            && preg_match('/^[a-z]{2,8}(?:-[a-z0-9]{1,8})*$/iD', $market->language) === 1;
    }

    public function collect(EngineRequest $request): EngineResult
    {
        if (app()->environment('production') && ! config('engines.gemini_grounding_approved', false)) {
            throw new EngineCallFailed('gemini_grounding_not_approved');
        }

        $apiKey = trim($request->apiKey ?? config('engines.gemini_api.api_key', ''));

        if ($apiKey === '') {
            throw new EngineCallFailed('gemini_missing_api_key');
        }

        $engine = Engine::query()->find($this->key());
        $model = trim($engine->model_id ?? '');
        $model = $model !== '' ? $model : config()->string('engines.gemini_api.model');
        $model = rawurlencode(Str::chopStart($model, 'models/'));

        $location = "country code {$request->country}";
        if ($request->city !== null && $request->city !== '') {
            $location .= ", city {$request->city}";
        }

        $prompt = $request->promptText."\n\nUse {$location} as the geographic context for searches and recommendations. "
            ."Respond in the language identified by the BCP 47 tag {$request->language}.";

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $apiKey])
                ->acceptJson()
                ->withoutRedirecting()
                ->timeout(120)
                ->retry([250, 500], when: self::shouldRetry(...), throw: false)
                ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                    'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                    'tools' => [['googleSearch' => (object) []]],
                ]);
        } catch (ConnectionException) {
            throw new EngineCallFailed('gemini_connection_failed');
        }

        if (! $response->successful()) {
            throw new EngineCallFailed('gemini_http_'.$response->status());
        }

        $raw = $response->json();

        if (! is_array($raw)) {
            throw new EngineCallFailed('gemini_invalid_response');
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
        $finishReason = data_get($raw, 'candidates.0.finishReason');
        $parts = data_get($raw, 'candidates.0.content.parts');
        $inputTokens = data_get($raw, 'usageMetadata.promptTokenCount');
        $outputTokens = data_get($raw, 'usageMetadata.candidatesTokenCount');
        $thoughtTokens = data_get($raw, 'usageMetadata.thoughtsTokenCount', 0);

        if ($finishReason !== 'STOP' || ! is_array($parts) || ! is_int($inputTokens) || ! is_int($outputTokens) || ! is_int($thoughtTokens)
            || min($inputTokens, $outputTokens, $thoughtTokens) < 0) {
            throw new EngineCallFailed('gemini_invalid_response');
        }

        $text = '';
        foreach ($parts as $part) {
            if (is_array($part) && ($part['thought'] ?? false) !== true && is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }

        if (trim($text) === '') {
            throw new EngineCallFailed('gemini_invalid_response');
        }

        $grounding = data_get($raw, 'candidates.0.groundingMetadata');

        return new EngineResult(
            answerText: $text,
            modelIdReported: is_string($raw['modelVersion'] ?? null) ? $raw['modelVersion'] : null,
            citations: $this->collectCitations(data_get($grounding, 'groundingChunks')),
            inputTokens: $inputTokens,
            outputTokens: $outputTokens + $thoughtTokens,
            searchCalls: $this->countSearches(data_get($grounding, 'webSearchQueries')),
            raw: $raw,
        );
    }

    /** @return list<array{url: string, title: string|null, rank: int, from_annotation: true}> */
    private function collectCitations(mixed $chunks): array
    {
        if (! is_array($chunks)) {
            return [];
        }

        $citations = [];
        foreach ($chunks as $chunk) {
            $title = data_get($chunk, 'web.title');
            $url = $this->sourceUrl(data_get($chunk, 'web.uri'), data_get($chunk, 'web.domain'), $title);

            if ($url === null || isset($citations[$url])) {
                continue;
            }

            $citations[$url] = [
                'url' => $url,
                'title' => is_string($title) ? $title : null,
                'rank' => count($citations) + 1,
                'from_annotation' => true,
            ];
        }

        return array_values($citations);
    }

    private function sourceUrl(mixed $uri, mixed $providerDomain, mixed $title): ?string
    {
        if (! is_string($uri) || filter_var($uri, FILTER_VALIDATE_URL) === false
            || ! in_array(strtolower((string) parse_url($uri, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return null;
        }

        if (UrlNormalizer::hostKey((string) parse_url($uri, PHP_URL_HOST)) !== self::GROUNDING_REDIRECT_HOST
            || ! str_starts_with((string) parse_url($uri, PHP_URL_PATH), '/grounding-api-redirect/')) {
            return $uri;
        }

        // The original link stays in the raw payload. Use a hostname for source attribution.
        foreach ([$providerDomain, $title] as $value) {
            if (! is_string($value)) {
                continue;
            }

            $domain = UrlNormalizer::hostKey(trim($value));
            if ($domain !== self::GROUNDING_REDIRECT_HOST && preg_match('/\.[a-z][a-z0-9-]*$/D', $domain) === 1
                && filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false) {
                return "https://{$domain}/";
            }
        }

        return null;
    }

    private function countSearches(mixed $queries): int
    {
        if (! is_array($queries)) {
            return 0;
        }

        $uniqueQueries = [];
        foreach ($queries as $query) {
            if (is_string($query) && trim($query) !== '') {
                $uniqueQueries[trim($query)] = true;
            }
        }

        return count($uniqueQueries);
    }
}
