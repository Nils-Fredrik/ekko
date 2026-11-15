<?php

namespace App\Engines\DataForSeo;

use App\Engines\EngineCallFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

final class DataForSeoClient
{
    /**
     * @param  list<array<string, bool|int|string>>|null  $tasks
     * @return array<array-key, mixed>
     */
    public function request(string $path, ?array $tasks = null, #[SensitiveParameter] ?string $apiKey = null): array
    {
        $credentials = $apiKey ?? config('engines.dataforseo.credentials');
        $pair = is_string($credentials) ? explode(':', $credentials, 2) : [];

        if (count($pair) !== 2 || $pair[0] === '' || $pair[1] === '') {
            throw new EngineCallFailed('dataforseo_invalid_credentials');
        }

        try {
            return retry([250, 500], function () use ($path, $tasks, $pair): array {
                $request = Http::baseUrl('https://api.dataforseo.com/v3')
                    ->withBasicAuth($pair[0], $pair[1])
                    ->acceptJson()
                    ->withoutRedirecting()
                    ->timeout(120);

                $response = $tasks === null ? $request->get($path) : $request->post($path, $tasks);

                if (! $response->successful()) {
                    throw new EngineCallFailed('dataforseo_http_'.$response->status());
                }

                $raw = $response->json();

                if (! is_array($raw)) {
                    throw new EngineCallFailed('dataforseo_invalid_response');
                }

                // DataForSEO reports API and task failures inside successful HTTP responses.
                $status = $raw['status_code'] ?? null;
                $taskStatus = data_get($raw, 'tasks.0.status_code');

                foreach ([$status, $taskStatus] as $code) {
                    if ($code === 40202 || $code === 40209 || (is_int($code) && $code >= 50000 && $code < 60000)) {
                        throw new EngineCallFailed('dataforseo_status_'.$code);
                    }
                }

                return $raw;
            }, when: static fn (Throwable $error): bool => $error instanceof EngineCallFailed
                && preg_match('/^dataforseo_(?:http_(?:429|5\d{2})|status_(?:40202|40209|5\d{4}))$/D', $error->errorCode) === 1);
        } catch (ConnectionException) {
            throw new EngineCallFailed('dataforseo_connection_failed');
        }
    }
}
