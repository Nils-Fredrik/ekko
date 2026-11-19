<?php

namespace App\Scheduling;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Verifies Google-signed OIDC tokens: the Cloud Scheduler ticks (ADR 0030) and the Cloud Tasks wake-ups (ADR 0033).
 * Each internal route names its own service account and audience, so a token of one sender is refused by the other.
 */
final class SchedulerIdentity
{
    /** Whether the bearer token is a valid, unexpired Google token for exactly this audience and service account. */
    public function accepts(?string $token, mixed $audience, mixed $email): bool
    {
        if (! is_string($token) || $token === '' || ! is_string($audience) || $audience === '' || ! is_string($email) || $email === '') {
            return false;
        }
        try {
            $claims = $this->verify($token);
        } catch (Throwable) {
            return false;
        }

        return ($claims['iss'] ?? null) === 'https://accounts.google.com'
            && ($claims['aud'] ?? null) === $audience && is_int($claims['exp'] ?? null) && $claims['exp'] > time()
            && ($claims['email'] ?? null) === $email && ($claims['email_verified'] ?? false) === true;
    }

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
