<?php

namespace App\Scheduling;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class SchedulerIdentity
{
    /** @return array<string, mixed> */
    public function verify(string $token): array
    {
        $certificates = Cache::store('database')->remember('scheduler:google-signing-keys', 3600, function (): array {
            $response = Http::acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(5)->get('https://www.googleapis.com/oauth2/v3/certs');
            $keys = $response->json();
            if (! $response->successful() || ! is_array($keys)) {
                throw new RuntimeException('scheduler_certificates_unavailable');
            }

            return $keys;
        });

        return (array) JWT::decode($token, JWK::parseKeySet($certificates));
    }
}
