<?php

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;

function schedulerToken(array $overrides = []): string
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $private);
    $details = openssl_pkey_get_details($key);
    $url64 = fn ($value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    $certs = ['keys' => [['kty' => 'RSA', 'alg' => 'RS256', 'kid' => 'fixture', 'n' => $url64($details['rsa']['n']), 'e' => $url64($details['rsa']['e'])]]];
    Http::preventStrayRequests()->fake(['https://www.googleapis.com/oauth2/v3/certs' => Http::response($certs)]);
    config(['collection.scheduler.audience' => 'https://ekko.example.test', 'collection.scheduler.service_account' => 'scheduler@example.iam.gserviceaccount.com']);

    return JWT::encode(array_replace([
        'iss' => 'https://accounts.google.com', 'aud' => 'https://ekko.example.test',
        'sub' => '12345', 'iat' => time() - 10, 'exp' => time() + 300,
        'email' => 'scheduler@example.iam.gserviceaccount.com', 'email_verified' => true,
    ], $overrides), $private, 'RS256', 'fixture');
}

test('internal poll accepts only a verified Google Scheduler identity', function () {
    $this->withToken(schedulerToken())->postJson('/internal/tick/extraction-poll')->assertNoContent();
});

test('internal poll rejects wrong identity, audience, issuer, expiry and signature', function (array $claims, bool $tamper) {
    $token = schedulerToken($claims);
    if ($tamper) {
        $parts = explode('.', $token);
        $parts[2] = 'invalid';
        $token = implode('.', $parts);
    }
    $this->withToken($token)->postJson('/internal/tick/extraction-poll')->assertUnauthorized();
})->with([
    [['email' => 'another@example.iam.gserviceaccount.com'], false],
    [['email_verified' => false], false],
    [['aud' => 'https://other.example.test'], false],
    [['iss' => 'https://attacker.example.test'], false],
    [['exp' => time() - 300], false],
    [[], true],
]);

test('neither a guest nor a platform admin can invoke the scheduler with a session', function () {
    $this->postJson('/internal/tick/extraction-poll')->assertUnauthorized();
    $this->actingAs(User::factory()->create(['is_platform_admin' => true]))->postJson('/internal/tick/extraction-poll')->assertUnauthorized();
});
