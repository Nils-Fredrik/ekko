<?php

namespace App\Engines\Credentials;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

final class ApiKeyVerifier
{
    public function verify(ApiKeyProvider $provider, #[SensitiveParameter] string $key): void
    {
        if (preg_match('/^[!-~]{1,4096}$/D', $key) !== 1) {
            throw ValidationException::withMessages(['key' => __('usage.invalid_key')]);
        }

        $request = Http::acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(8);
        [$url, $headers, $field] = match ($provider) {
            ApiKeyProvider::OpenAi => ['https://api.openai.com/v1/models', ['Authorization' => "Bearer {$key}"], 'data'],
            ApiKeyProvider::Anthropic => ['https://api.anthropic.com/v1/models', [
                'x-api-key' => $key,
                'anthropic-version' => '2023-06-01',
            ], 'data'],
            ApiKeyProvider::Google => ['https://generativelanguage.googleapis.com/v1beta/models', ['x-goog-api-key' => $key], 'models'],
            ApiKeyProvider::Perplexity => ['https://api.perplexity.ai/v1/models', ['Authorization' => "Bearer {$key}"], 'data'],
        };

        try {
            $response = $request->withHeaders($headers)->get($url);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['key' => __('usage.key_verification_unavailable')]);
        }

        if ($response->serverError() || $response->tooManyRequests()) {
            throw ValidationException::withMessages(['key' => __('usage.key_verification_unavailable')]);
        }

        if (! $response->successful() || ! is_array($response->json($field))) {
            throw ValidationException::withMessages(['key' => __('usage.invalid_key')]);
        }
    }
}
