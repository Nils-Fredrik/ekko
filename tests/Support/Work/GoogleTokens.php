<?php

namespace Tests\Support\Work;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;

/**
 * Google-style OIDC tokens signed with a key made for the test, and Google's certificate endpoint faked to serve it.
 * One key per instance: the verifier caches the certificates.
 */
final class GoogleTokens
{
    public const TASKS = 'ekko-tasks@ekko-fixture.iam.gserviceaccount.com';

    public const TASKS_AUDIENCE = 'https://ekko.example.test/internal/work';

    public const SCHEDULER = 'scheduler@example.iam.gserviceaccount.com';

    public const SCHEDULER_AUDIENCE = 'https://ekko.example.test';

    private string $private;

    public function __construct()
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $this->private = $private;
        $details = openssl_pkey_get_details($key);
        $url64 = fn ($value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        Http::fake(['https://www.googleapis.com/oauth2/v3/certs' => Http::response(['keys' => [[
            'kty' => 'RSA', 'alg' => 'RS256', 'kid' => 'fixture', 'n' => $url64($details['rsa']['n']), 'e' => $url64($details['rsa']['e']),
        ]]])]);
        config([
            'collection.scheduler.audience' => self::SCHEDULER_AUDIENCE,
            'collection.scheduler.service_account' => self::SCHEDULER,
            'ekko.work.cloud_tasks.audience' => self::TASKS_AUDIENCE,
            'ekko.work.cloud_tasks.service_account' => self::TASKS,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    public function tasks(array $overrides = []): string
    {
        return $this->sign(['aud' => self::TASKS_AUDIENCE, 'email' => self::TASKS, ...$overrides]);
    }

    /** @param array<string, mixed> $overrides */
    public function scheduler(array $overrides = []): string
    {
        return $this->sign(['aud' => self::SCHEDULER_AUDIENCE, 'email' => self::SCHEDULER, ...$overrides]);
    }

    /** @param array<string, mixed> $claims */
    private function sign(array $claims): string
    {
        return JWT::encode([
            'iss' => 'https://accounts.google.com', 'sub' => '12345', 'iat' => time() - 10, 'exp' => time() + 300, 'email_verified' => true,
            ...$claims,
        ], $this->private, 'RS256', 'fixture');
    }
}
