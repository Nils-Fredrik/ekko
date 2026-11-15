<?php

test('forwarded HTTPS produces secure OAuth metadata and frontend URLs', function () {
    $this->withHeaders(['X-Forwarded-Proto' => 'https'])
        ->getJson('http://candidate.example.test/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJsonPath('issuer', 'https://candidate.example.test')
        ->assertJsonPath('authorization_endpoint', 'https://candidate.example.test/oauth/authorize');

    expect(route('login'))->toBe('https://candidate.example.test/login')
        ->and(asset('build/proxy-check.js'))->toBe('https://candidate.example.test/build/proxy-check.js');
});

test('forwarded host and port cannot replace the external request origin', function () {
    $this->withHeaders([
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'untrusted.example',
        'X-Forwarded-Port' => '1234',
    ])->getJson('http://candidate.example.test/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJsonPath('issuer', 'https://candidate.example.test');
});

test('local HTTP remains usable without a forwarded protocol', function () {
    $this->getJson('http://candidate.example.test/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJsonPath('issuer', 'http://candidate.example.test');
});
