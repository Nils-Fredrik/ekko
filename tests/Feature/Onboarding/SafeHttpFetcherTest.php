<?php

// docs/spec.md 16: SSRF protection for the wizard's page fetching. DNS and HTTP are fakes, so what is tested is
// the decision logic: which address is allowed, what is pinned, and what happens on redirects and large bodies.

use App\Onboarding\Fetch\FetchFailed;
use App\Onboarding\Fetch\SafeHttpFetcher;
use App\Onboarding\Fetch\TransportResponse;
use Tests\Support\Onboarding\FakeHostResolver;
use Tests\Support\Onboarding\FakePageTransport;

function fetcher(array $routes, array $hosts = ['*' => ['93.184.216.34']]): array
{
    $resolver = new FakeHostResolver($hosts);
    $transport = new FakePageTransport($routes);

    return [new SafeHttpFetcher($resolver, $transport), $resolver, $transport];
}

function fetchReason(callable $fetch): string
{
    try {
        $fetch();
    } catch (FetchFailed $e) {
        return $e->reason;
    }

    return 'fetched';
}

it('fetches a page and pins the address it checked', function () {
    [$fetcher, $resolver, $transport] = fetcher(['https://eksempel.no/' => FakePageTransport::html('<p>hei</p>')]);

    $page = $fetcher->get('https://eksempel.no/', ['text/html']);

    expect($page->url)->toBe('https://eksempel.no/')
        ->and($page->body)->toBe('<p>hei</p>')
        ->and($page->contentType)->toBe('text/html')
        ->and($transport->requests)->toHaveCount(1)
        ->and($transport->requests[0]['ip'])->toBe('93.184.216.34')
        ->and($transport->requests[0]['host'])->toBe('eksempel.no')
        ->and($resolver->lookups)->toBe(['eksempel.no']);
});

it('refuses a host that resolves to a private, loopback, link-local or metadata address', function (string $ip) {
    [$fetcher, , $transport] = fetcher(['https://eksempel.no/' => FakePageTransport::html('x')], ['*' => [$ip]]);

    expect(fetchReason(fn () => $fetcher->get('https://eksempel.no/', ['text/html'])))->toBe(FetchFailed::BLOCKED_ADDRESS)
        ->and($transport->requests)->toBe([]);
})->with([
    'loopback' => '127.0.0.1',
    'private' => '10.1.2.3',
    'private 192' => '192.168.0.10',
    'private 172' => '172.20.0.1',
    'metadata' => '169.254.169.254',
    'carrier-grade NAT' => '100.64.1.1',
    'IPv6 loopback' => '::1',
    'IPv6 unique local' => 'fd00:ec2::254',
    'IPv6 link-local' => 'fe80::1',
    'IPv4-mapped IPv6 loopback' => '::ffff:127.0.0.1',
    'NAT64 metadata' => '64:ff9b::a9fe:a9fe',
]);

it('refuses a host when any one of its addresses is private, so a mixed answer cannot pick', function () {
    [$fetcher, , $transport] = fetcher(['https://eksempel.no/' => FakePageTransport::html('x')], ['*' => ['93.184.216.34', '10.0.0.5']]);

    expect(fetchReason(fn () => $fetcher->get('https://eksempel.no/', ['text/html'])))->toBe(FetchFailed::BLOCKED_ADDRESS)
        ->and($transport->requests)->toBe([]);
});

it('refuses a host that does not resolve', function () {
    [$fetcher] = fetcher([], ['*' => []]);

    expect(fetchReason(fn () => $fetcher->get('https://finnes-ikke.no/', ['text/html'])))->toBe(FetchFailed::DNS);
});

it('prefers an IPv4 address and falls back to IPv6', function () {
    [$fetcher, , $transport] = fetcher(['https://eksempel.no/' => FakePageTransport::html('x')], ['*' => ['2606:4700:4700::1111', '93.184.216.34']]);
    $fetcher->get('https://eksempel.no/', ['text/html']);

    [$fetcher, , $transport6] = fetcher(['https://eksempel.no/' => FakePageTransport::html('x')], ['*' => ['2606:4700:4700::1111']]);
    $fetcher->get('https://eksempel.no/', ['text/html']);

    expect($transport->requests[0]['ip'])->toBe('93.184.216.34')
        ->and($transport6->requests[0]['ip'])->toBe('2606:4700:4700::1111');
});

it('refuses addresses that are not https on the default port without any lookup', function (string $url) {
    [$fetcher, $resolver, $transport] = fetcher([]);

    expect(fetchReason(fn () => $fetcher->get($url, ['text/html'])))->toBe(FetchFailed::INVALID_URL)
        ->and($resolver->lookups)->toBe([])
        ->and($transport->requests)->toBe([]);
})->with([
    'http' => 'http://eksempel.no/',
    'other port' => 'https://eksempel.no:8443/',
    'credentials' => 'https://a:b@eksempel.no/',
    'ip literal' => 'https://127.0.0.1/',
    'decimal ip' => 'https://2130706433/',
    'ipv6 literal' => 'https://[::1]/',
    'file' => 'file:///etc/passwd',
]);

it('follows a redirect and checks the new host again', function () {
    [$fetcher, $resolver, $transport] = fetcher([
        'https://eksempel.no/' => FakePageTransport::redirect('https://www.eksempel.no/hjem'),
        'https://www.eksempel.no/hjem' => FakePageTransport::html('hjem'),
    ]);

    $page = $fetcher->get('https://eksempel.no/', ['text/html']);

    expect($page->url)->toBe('https://www.eksempel.no/hjem')
        ->and($resolver->lookups)->toBe(['eksempel.no', 'www.eksempel.no'])
        ->and(array_column($transport->requests, 'ip'))->toBe(['93.184.216.34', '93.184.216.34']);
});

it('follows a relative redirect', function () {
    [$fetcher] = fetcher([
        'https://eksempel.no/a/b' => FakePageTransport::redirect('../c'),
        'https://eksempel.no/c' => FakePageTransport::html('c'),
    ]);

    expect($fetcher->get('https://eksempel.no/a/b', ['text/html'])->url)->toBe('https://eksempel.no/c');
});

it('refuses a redirect to a host that resolves to a private address, even though the first host was public', function () {
    [$fetcher, , $transport] = fetcher(
        ['https://eksempel.no/' => FakePageTransport::redirect('https://intern.eksempel.no/admin'), 'https://intern.eksempel.no/admin' => FakePageTransport::html('hemmelig')],
        ['eksempel.no' => ['93.184.216.34'], 'intern.eksempel.no' => ['10.0.0.7']],
    );

    expect(fetchReason(fn () => $fetcher->get('https://eksempel.no/', ['text/html'])))->toBe(FetchFailed::BLOCKED_ADDRESS)
        ->and($transport->urls())->toBe(['https://eksempel.no/']);
});

it('refuses a redirect to an address that is not allowed', function (string $location) {
    [$fetcher, , $transport] = fetcher(['https://eksempel.no/' => FakePageTransport::redirect($location)]);

    expect(fetchReason(fn () => $fetcher->get('https://eksempel.no/', ['text/html'])))->toBe(FetchFailed::INVALID_URL)
        ->and($transport->urls())->toBe(['https://eksempel.no/']);
})->with([
    'http downgrade' => 'http://eksempel.no/',
    'metadata ip' => 'https://169.254.169.254/latest/meta-data/',
    'loopback' => 'https://127.0.0.1/',
    'decimal loopback' => 'https://2130706433/',
    'ipv6 loopback' => 'https://[::1]/',
    'localhost' => 'https://localhost/',
    'internal name' => 'https://metadata.google.internal/',
    'other port' => 'https://eksempel.no:8443/',
    'credentials' => 'https://user:pass@eksempel.no/',
    'file' => 'file:///etc/passwd',
    'gopher' => 'gopher://eksempel.no/',
    'protocol-relative to ip' => '//127.0.0.1/',
]);

it('refuses a redirect without a usable Location', function () {
    [$fetcher] = fetcher(['https://eksempel.no/' => new TransportResponse(302, 'text/html', null, '')]);

    expect(fetchReason(fn () => $fetcher->get('https://eksempel.no/', ['text/html'])))->toBe(FetchFailed::HTTP_ERROR);
});

it('allows three redirects and refuses the fourth', function () {
    $routes = [
        'https://eksempel.no/1' => FakePageTransport::redirect('/2'),
        'https://eksempel.no/2' => FakePageTransport::redirect('/3'),
        'https://eksempel.no/3' => FakePageTransport::redirect('/4'),
        'https://eksempel.no/4' => FakePageTransport::html('fire'),
    ];
    [$fetcher] = fetcher($routes);
    expect($fetcher->get('https://eksempel.no/1', ['text/html'])->body)->toBe('fire');

    $routes['https://eksempel.no/4'] = FakePageTransport::redirect('/5');
    $routes['https://eksempel.no/5'] = FakePageTransport::html('fem');
    [$fetcher, , $transport] = fetcher($routes);

    expect(fetchReason(fn () => $fetcher->get('https://eksempel.no/1', ['text/html'])))->toBe(FetchFailed::TOO_MANY_REDIRECTS)
        ->and($transport->requests)->toHaveCount(4);
});

it('stops a redirect loop', function () {
    [$fetcher] = fetcher(['https://eksempel.no/a' => FakePageTransport::redirect('/a')]);

    expect(fetchReason(fn () => $fetcher->get('https://eksempel.no/a', ['text/html'])))->toBe(FetchFailed::TOO_MANY_REDIRECTS);
});

it('asks the transport for at most 2 MB', function () {
    [$fetcher, , $transport] = fetcher(['https://eksempel.no/' => FakePageTransport::html('x')]);
    $fetcher->get('https://eksempel.no/', ['text/html']);

    expect($transport->requests[0]['maxBytes'])->toBe(2 * 1024 * 1024)
        ->and($transport->requests[0]['timeout'])->toBe(10);
});

it('refuses a body over 2 MB, however the transport reports it', function (TransportResponse $response) {
    [$fetcher] = fetcher(['https://eksempel.no/' => $response]);

    expect(fetchReason(fn () => $fetcher->get('https://eksempel.no/', ['text/html'])))->toBe(FetchFailed::TOO_LARGE);
})->with([
    'cut off by the transport' => fn () => new TransportResponse(200, 'text/html', null, str_repeat('a', 100), truncated: true),
    'longer than the limit' => fn () => new TransportResponse(200, 'text/html', null, str_repeat('a', 2 * 1024 * 1024 + 1)),
    'declared length' => fn () => new TransportResponse(200, 'text/html', null, 'a', contentLength: 5_000_000),
]);

it('accepts a body of exactly 2 MB', function () {
    [$fetcher] = fetcher(['https://eksempel.no/' => FakePageTransport::html(str_repeat('a', 2 * 1024 * 1024))]);

    expect(strlen($fetcher->get('https://eksempel.no/', ['text/html'])->body))->toBe(2 * 1024 * 1024);
});

it('only accepts the media types it was asked for', function (?string $type, bool $accepted) {
    [$fetcher] = fetcher(['https://eksempel.no/' => new TransportResponse(200, $type, null, 'x')]);

    expect(fetchReason(fn () => $fetcher->get('https://eksempel.no/', ['text/html', 'application/xhtml+xml'])))
        ->toBe($accepted ? 'fetched' : FetchFailed::UNSUPPORTED_CONTENT);
})->with([
    ['text/html', true],
    ['text/html; charset=UTF-8', true],
    ['TEXT/HTML', true],
    ['application/xhtml+xml', true],
    ['application/pdf', false],
    ['image/png', false],
    ['application/octet-stream', false],
    ['application/json', false],
    ['text/html-but-not', false],
    [null, false],
]);

it('refuses an error status', function (int $status) {
    [$fetcher] = fetcher(['https://eksempel.no/' => new TransportResponse($status, 'text/html', null, 'x')]);

    expect(fetchReason(fn () => $fetcher->get('https://eksempel.no/', ['text/html'])))->toBe(FetchFailed::HTTP_ERROR);
})->with([199, 403, 404, 410, 429, 500, 503]);

it('does not start a request after the deadline', function () {
    [$fetcher, , $transport] = fetcher(['https://eksempel.no/' => FakePageTransport::html('x')]);

    expect(fetchReason(fn () => $fetcher->get('https://eksempel.no/', ['text/html'], microtime(true) - 1)))->toBe(FetchFailed::TIMEOUT)
        ->and($transport->requests)->toBe([]);
});

it('never gives a request more time than is left before the deadline', function () {
    [$fetcher, , $transport] = fetcher(['https://eksempel.no/' => FakePageTransport::html('x')]);
    $fetcher->get('https://eksempel.no/', ['text/html'], microtime(true) + 3.5);

    expect($transport->requests[0]['timeout'])->toBeLessThanOrEqual(3)->toBeGreaterThanOrEqual(1);
});

it('passes on a failure from the transport', function () {
    [$fetcher] = fetcher(['https://eksempel.no/' => FetchFailed::because(FetchFailed::TIMEOUT)]);

    expect(fetchReason(fn () => $fetcher->get('https://eksempel.no/', ['text/html'])))->toBe(FetchFailed::TIMEOUT);
});

it('never puts the address or the page in the failure message', function () {
    [$fetcher] = fetcher(['https://hemmelig.eksempel.no/side' => new TransportResponse(500, 'text/html', null, 'SECRET-BODY')]);

    try {
        $fetcher->get('https://hemmelig.eksempel.no/side', ['text/html']);
    } catch (FetchFailed $e) {
        expect($e->getMessage())->not->toContain('hemmelig')->not->toContain('SECRET-BODY');
    }
});
