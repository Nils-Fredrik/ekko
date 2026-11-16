<?php

// T39-F3: behind Cloud Run every request arrives from Google's front end. Without trusted proxies all clients
// would share one address, and the per-IP rate limits (login, SSO, OAuth) would be one bucket for everyone.

beforeEach(function () {
    config(['services.google.client_id' => 'test-id', 'services.google.client_secret' => 'test-secret']);
});

function fromFrontEnd(string $forwardedFor): array
{
    return ['REMOTE_ADDR' => '169.254.1.1', 'HTTP_X_FORWARDED_FOR' => $forwardedFor];
}

test('with trusted proxies, each client behind the front end gets its own SSO rate limit', function () {
    config(['trustedproxy.proxies' => '*']);

    foreach (range(1, 10) as $_) {
        $this->withServerVariables(fromFrontEnd('203.0.113.10'))->get('/auth/google/redirect')->assertRedirect();
    }
    $this->withServerVariables(fromFrontEnd('203.0.113.10'))->get('/auth/google/redirect')->assertStatus(429);

    $this->withServerVariables(fromFrontEnd('203.0.113.20'))->get('/auth/google/redirect')->assertRedirect();
});

test('a client cannot pick its own address by sending X-Forwarded-For', function () {
    config(['trustedproxy.proxies' => '*']);

    // The front end appends the real address last. What the client sent comes before it and is ignored,
    // so changing it does not give a fresh bucket.
    foreach (range(1, 10) as $i) {
        $this->withServerVariables(fromFrontEnd("198.51.100.{$i}, 203.0.113.10"))->get('/auth/google/redirect')->assertRedirect();
    }
    $this->withServerVariables(fromFrontEnd('198.51.100.99, 203.0.113.10'))->get('/auth/google/redirect')->assertStatus(429);
});

test('without trusted proxies, forwarded headers are ignored', function () {
    config(['trustedproxy.proxies' => null]);

    foreach (range(1, 10) as $i) {
        $this->withServerVariables(fromFrontEnd("203.0.113.{$i}"))->get('/auth/google/redirect')->assertRedirect();
    }
    $this->withServerVariables(fromFrontEnd('203.0.113.99'))->get('/auth/google/redirect')->assertStatus(429);
});

test('the scheme is taken from the proxy, but the host never is', function () {
    config(['trustedproxy.proxies' => '*', 'app.url' => 'http://localhost']);

    $this->withServerVariables(fromFrontEnd('203.0.113.10') + [
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_HOST' => 'evil.example',
    ])->get('/login')->assertOk();

    $request = request();
    expect($request->ip())->toBe('203.0.113.10')
        ->and($request->isSecure())->toBeTrue()
        ->and($request->getHost())->toBe('localhost');
});
