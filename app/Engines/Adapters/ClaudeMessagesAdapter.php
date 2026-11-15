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

abstract class ClaudeMessagesAdapter implements EngineAdapter
{
    abstract protected function defaultModel(): string;

    public function supports(Market $market): bool
    {
        return preg_match('/^[A-Z]{2}$/D', $market->country) === 1
            && preg_match('/^[a-z]{2,8}(?:-[a-z0-9]{1,8})*$/iD', $market->language) === 1;
    }

    public function collect(EngineRequest $request): EngineResult
    {
        $apiKey = trim($request->apiKey ?? (string) config('services.anthropic.key'));

        if ($apiKey === '') {
            throw new EngineCallFailed('anthropic_missing_api_key');
        }

        $engine = Engine::query()->find($this->key());
        $model = trim($engine->model_id ?? '');
        $location = ['type' => 'approximate', 'country' => $request->country];

        if ($request->city !== null && $request->city !== '') {
            $location['city'] = $request->city;
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])
                ->acceptJson()
                ->withoutRedirecting()
                ->timeout(120)
                ->retry([250, 500], when: self::shouldRetry(...), throw: false)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $model !== '' ? $model : $this->defaultModel(),
                    'max_tokens' => 4096,
                    'messages' => [[
                        'role' => 'user',
                        'content' => $request->promptText."\n\nRespond in the language identified by the BCP 47 tag {$request->language}.",
                    ]],
                    'tools' => [[
                        'type' => 'web_search_20250305',
                        'name' => 'web_search',
                        'max_uses' => 2,
                        'user_location' => $location,
                    ]],
                ]);
        } catch (ConnectionException) {
            throw new EngineCallFailed('anthropic_connection_failed');
        }

        if (! $response->successful()) {
            throw new EngineCallFailed('anthropic_http_'.$response->status());
        }

        $raw = $response->json();

        if (! is_array($raw)) {
            throw new EngineCallFailed('anthropic_invalid_response');
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
        $content = $raw['content'] ?? null;
        $inputTokens = data_get($raw, 'usage.input_tokens');
        $outputTokens = data_get($raw, 'usage.output_tokens');
        $searchCalls = data_get($raw, 'usage.server_tool_use.web_search_requests', 0);

        if (($raw['stop_reason'] ?? null) !== 'end_turn' || ! is_array($content)
            || ! is_int($inputTokens) || ! is_int($outputTokens) || ! is_int($searchCalls)
            || min($inputTokens, $outputTokens, $searchCalls) < 0) {
            throw new EngineCallFailed('anthropic_invalid_response');
        }

        $text = '';
        $separator = '';
        $citations = [];

        foreach ($content as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? null) === 'web_search_tool_result'
                && data_get($block, 'content.type') === 'web_search_tool_result_error'
                && data_get($block, 'content.error_code') !== 'max_uses_exceeded') {
                throw new EngineCallFailed('anthropic_web_search_failed');
            }

            if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $separator.$block['text'];
                $separator = '';
                $citations = $this->collectCitations($block['citations'] ?? [], $citations);
            } elseif ($text !== '') {
                $separator = "\n\n";
            }
        }

        if (trim($text) === '') {
            throw new EngineCallFailed('anthropic_invalid_response');
        }

        return new EngineResult(
            answerText: $text,
            modelIdReported: is_string($raw['model'] ?? null) ? $raw['model'] : null,
            citations: array_values($citations),
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            searchCalls: $searchCalls,
            raw: $raw,
        );
    }

    /**
     * @param  array<string, array{url: string, title: string|null, rank: int, from_annotation: true}>  $citations
     * @return array<string, array{url: string, title: string|null, rank: int, from_annotation: true}>
     */
    private function collectCitations(mixed $annotations, array $citations): array
    {
        if (! is_array($annotations)) {
            return $citations;
        }

        foreach ($annotations as $annotation) {
            if (! is_array($annotation) || ($annotation['type'] ?? null) !== 'web_search_result_location') {
                continue;
            }

            $url = $annotation['url'] ?? null;
            if (! is_string($url) || isset($citations[$url]) || filter_var($url, FILTER_VALIDATE_URL) === false
                || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                continue;
            }

            $citations[$url] = [
                'url' => $url,
                'title' => is_string($annotation['title'] ?? null) ? $annotation['title'] : null,
                'rank' => count($citations) + 1,
                'from_annotation' => true,
            ];
        }

        return $citations;
    }
}
