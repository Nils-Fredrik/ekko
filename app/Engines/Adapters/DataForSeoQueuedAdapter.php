<?php

namespace App\Engines\Adapters;

use App\Engines\DataForSeo\DataForSeoClient;
use App\Engines\DataForSeo\DataForSeoMarkets;
use App\Engines\EngineCallFailed;
use App\Engines\EngineRequest;
use App\Engines\EngineResult;
use App\Engines\QueuedEngineAdapter;
use App\Models\Market;
use LogicException;
use SensitiveParameter;

abstract class DataForSeoQueuedAdapter implements QueuedEngineAdapter
{
    protected const string PATH = '';

    protected const ?string LOCATIONS_PATH = null;

    /** @var array<string, bool|int|string> */
    protected const array EXTRA_TASK_PARAMETERS = [];

    public function __construct(
        private readonly DataForSeoClient $client,
        private readonly DataForSeoMarkets $markets,
    ) {}

    final public function supports(Market $market): bool
    {
        return $this->markets->supports($market->country, $market->language);
    }

    final public function collect(EngineRequest $request): EngineResult
    {
        throw new LogicException('DataForSEO results must be collected through the standard queue.');
    }

    final public function submit(EngineRequest $request): string
    {
        $parameters = $this->markets->parameters(
            $request->country,
            $request->language,
            $request->city,
            static::LOCATIONS_PATH,
            $request->apiKey,
        );
        $raw = $this->client->request(static::PATH.'/task_post', [[
            'keyword' => strtr($request->promptText, ['%' => '%25', '+' => '%2B']),
            'priority' => 1,
            'tag' => $request->runId,
        ] + $parameters + static::EXTRA_TASK_PARAMETERS], $request->apiKey);

        $this->checkStatus($raw['status_code'] ?? null, 20000);
        $this->checkStatus(data_get($raw, 'tasks.0.status_code'), 20100);

        $id = data_get($raw, 'tasks.0.id');

        if (! is_string($id) || $id === '') {
            throw new EngineCallFailed('dataforseo_invalid_response');
        }

        return $id;
    }

    /** @return list<string> */
    final public function tasksReady(#[SensitiveParameter] ?string $apiKey = null): array
    {
        $raw = $this->client->request(static::PATH.'/tasks_ready', apiKey: $apiKey);
        $this->checkStatus($raw['status_code'] ?? null, 20000);

        $tasks = $raw['tasks'] ?? [];
        $ids = [];

        if (! is_array($tasks)) {
            throw new EngineCallFailed('dataforseo_invalid_response');
        }

        foreach ($tasks as $task) {
            $this->checkStatus(data_get($task, 'status_code'), 20000);
            $readyTasks = data_get($task, 'result') ?? [];

            if (! is_array($readyTasks)) {
                throw new EngineCallFailed('dataforseo_invalid_response');
            }

            foreach ($readyTasks as $ready) {
                $id = data_get($ready, 'id');

                if (is_string($id) && $id !== '') {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    final public function fetch(string $providerTaskId, #[SensitiveParameter] ?string $apiKey = null): ?EngineResult
    {
        $raw = $this->client->request(static::PATH.'/task_get/advanced/'.rawurlencode($providerTaskId), apiKey: $apiKey);
        $status = ($raw['status_code'] ?? null) === 20000
            ? data_get($raw, 'tasks.0.status_code')
            : ($raw['status_code'] ?? null);

        if (in_array($status, [40601, 40602], true)) {
            return null;
        }

        $this->checkStatus($raw['status_code'] ?? null, 20000);

        // A partially retrieved SERP can still contain the requested AI answer.
        if ($status !== 40106) {
            $this->checkStatus($status, 20000);
        }

        $result = data_get($raw, 'tasks.0.result.0');

        if (data_get($raw, 'tasks.0.id') !== $providerTaskId || ! is_array($result)) {
            throw new EngineCallFailed('dataforseo_invalid_response');
        }

        return $this->parseResult($result, $raw);
    }

    /**
     * @param  array<array-key, mixed>  $result
     * @param  array<array-key, mixed>  $raw
     */
    abstract protected function parseResult(array $result, array $raw): EngineResult;

    /**
     * @param  iterable<mixed>  $sources
     * @param  array<string, array{url: string, title: ?string, rank: int, from_annotation: true}>  $citations
     * @return array<string, array{url: string, title: ?string, rank: int, from_annotation: true}>
     */
    final protected function appendCitations(iterable $sources, array $citations = []): array
    {
        foreach ($sources as $source) {
            $url = data_get($source, 'url');

            if (! is_string($url) || isset($citations[$url]) || filter_var($url, FILTER_VALIDATE_URL) === false
                || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                continue;
            }

            $title = data_get($source, 'title');
            $citations[$url] = [
                'url' => $url,
                'title' => is_string($title) ? $title : null,
                'rank' => count($citations) + 1,
                'from_annotation' => true,
            ];
        }

        return $citations;
    }

    private function checkStatus(mixed $status, int $expected): void
    {
        if ($status !== $expected) {
            throw new EngineCallFailed(is_int($status) ? 'dataforseo_status_'.$status : 'dataforseo_invalid_response');
        }
    }
}
