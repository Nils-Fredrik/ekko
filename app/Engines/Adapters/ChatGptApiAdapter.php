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

final class ChatGptApiAdapter implements EngineAdapter
{
    public function key(): string
    {
        return 'chatgpt_api';
    }

    public function supports(Market $market): bool
    {
        return preg_match('/^[A-Z]{2}$/D', $market->country) === 1
            && preg_match('/^[a-z]{2,8}(?:-[a-z0-9]{1,8})*$/iD', $market->language) === 1;
    }

    public function collect(EngineRequest $request): EngineResult
    {
        $apiKey = $request->apiKey ?? config()->string('engines.chatgpt_api.api_key');

        if ($apiKey === '') {
            throw new EngineCallFailed('openai_missing_api_key');
        }

        $engine = Engine::query()->find($this->key());
        $model = $engine?->model_id ?? config()->string('engines.chatgpt_api.model');
        $location = ['type' => 'approximate', 'country' => $request->country];

        if ($request->city !== null && $request->city !== '') {
            $location['city'] = $request->city;
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->withoutRedirecting()
                ->timeout(120)
                ->retry([250, 500], when: self::shouldRetry(...), throw: false)
                ->post('https://api.openai.com/v1/responses', [
                    'model' => $model,
                    'input' => $request->promptText."\n\nRespond in the language identified by the BCP 47 tag {$request->language}.",
                    'store' => false,
                    'tools' => [['type' => 'web_search', 'user_location' => $location]],
                    'tool_choice' => 'required',
                ]);
        } catch (ConnectionException) {
            throw new EngineCallFailed('openai_connection_failed');
        }

        if (! $response->successful()) {
            throw new EngineCallFailed('openai_http_'.$response->status());
        }

        $raw = $response->json();

        if (! is_array($raw) || ($raw['status'] ?? null) !== 'completed') {
            throw new EngineCallFailed('openai_invalid_response');
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

        if (! is_array($output) || ! is_int($inputTokens) || ! is_int($outputTokens)
            || min($inputTokens, $outputTokens) < 0) {
            throw new EngineCallFailed('openai_invalid_response');
        }

        $parts = [];
        $citations = [];
        $searchCalls = 0;

        foreach ($output as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (($item['type'] ?? null) === 'web_search_call'
                && ($item['status'] ?? null) === 'completed'
                && data_get($item, 'action.type') === 'search') {
                $searchCalls++;
            }

            if (($item['type'] ?? null) !== 'message' || ($item['role'] ?? null) !== 'assistant'
                || ! is_array($item['content'] ?? null)) {
                continue;
            }

            foreach ($item['content'] as $part) {
                if (! is_array($part)) {
                    continue;
                }

                if (($part['type'] ?? null) === 'refusal' && is_string($part['refusal'] ?? null)) {
                    $parts[] = $part['refusal'];
                }

                if (($part['type'] ?? null) === 'output_text' && is_string($part['text'] ?? null)) {
                    $parts[] = $part['text'];
                    $citations = $this->collectCitations($part['annotations'] ?? [], $citations);
                }
            }
        }

        $text = implode("\n\n", $parts);

        if (trim($text) === '') {
            throw new EngineCallFailed('openai_invalid_response');
        }

        return new EngineResult(
            answerText: $text,
            modelIdReported: is_string($raw['model'] ?? null) ? $raw['model'] : null,
            citations: $citations,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            searchCalls: $searchCalls,
            raw: $raw,
        );
    }

    /**
     * @param  list<array{url: string, title: string|null, rank: int, from_annotation: true}>  $citations
     * @return list<array{url: string, title: string|null, rank: int, from_annotation: true}>
     */
    private function collectCitations(mixed $annotations, array $citations): array
    {
        if (! is_array($annotations)) {
            return $citations;
        }

        foreach ($annotations as $annotation) {
            if (! is_array($annotation) || ($annotation['type'] ?? null) !== 'url_citation') {
                continue;
            }

            $url = $annotation['url'] ?? null;
            if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false
                || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                continue;
            }

            $citations[] = [
                'url' => $url,
                'title' => is_string($annotation['title'] ?? null) ? $annotation['title'] : null,
                'rank' => count($citations) + 1,
                'from_annotation' => true,
            ];
        }

        return $citations;
    }
}
