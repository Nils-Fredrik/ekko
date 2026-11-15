<?php

// The real transport against Laravel's HTTP fake: what it asks cURL for, and how it reads the body. The pinning
// itself (CURLOPT_RESOLVE) is checked on the options the request was made with.

use App\Onboarding\Fetch\FetchFailed;
use App\Onboarding\Fetch\HttpPageTransport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('pins the connection to the checked address and never follows redirects', function () {
    $seen = null;
    Http::fake(function (Request $request, array $options) use (&$seen) {
        $seen = $options;

        return Http::response('<p>hei</p>', 200, ['Content-Type' => 'text/html']);
    });

    $response = (new HttpPageTransport)->get('https://eksempel.no/', 'eksempel.no', '93.184.216.34', 1000, 7);

    expect($response->status)->toBe(200)
        ->and($response->body)->toBe('<p>hei</p>')
        ->and($response->contentType)->toBe('text/html')
        ->and($seen['curl'][CURLOPT_RESOLVE])->toBe(['eksempel.no:443:93.184.216.34'])
        ->and($seen['allow_redirects'])->toBeFalse()
        ->and($seen['stream'])->toBeTrue()
        ->and($seen['timeout'])->toBe(7)
        ->and($seen['connect_timeout'])->toBe(5)
        ->and($seen['verify'])->toBeTrue();
});

it('wraps an IPv6 address in brackets for cURL', function () {
    $seen = null;
    Http::fake(function (Request $request, array $options) use (&$seen) {
        $seen = $options;

        return Http::response('x', 200, ['Content-Type' => 'text/html']);
    });

    (new HttpPageTransport)->get('https://eksempel.no/', 'eksempel.no', '2606:4700:4700::1111', 1000, 7);

    expect($seen['curl'][CURLOPT_RESOLVE])->toBe(['eksempel.no:443:[2606:4700:4700::1111]']);
});

it('reports a redirect without following it', function () {
    Http::fake(['*' => Http::response('', 302, ['Location' => '/ny'])]);

    $response = (new HttpPageTransport)->get('https://eksempel.no/', 'eksempel.no', '93.184.216.34', 1000, 7);

    expect($response->status)->toBe(302)->and($response->location)->toBe('/ny');
    Http::assertSentCount(1);
});

it('reads at most the limit and one byte more, and says it was cut off', function () {
    Http::fake(['*' => Http::response(str_repeat('a', 50_000), 200, ['Content-Type' => 'text/html'])]);

    $response = (new HttpPageTransport)->get('https://eksempel.no/', 'eksempel.no', '93.184.216.34', 20_000, 7);

    expect($response->truncated)->toBeTrue()->and(strlen($response->body))->toBe(20_001);
});

it('does not mark a body at the limit as cut off', function () {
    Http::fake(['*' => Http::response(str_repeat('a', 20_000), 200, ['Content-Type' => 'text/html'])]);

    $response = (new HttpPageTransport)->get('https://eksempel.no/', 'eksempel.no', '93.184.216.34', 20_000, 7);

    expect($response->truncated)->toBeFalse()->and(strlen($response->body))->toBe(20_000);
});

it('turns a connection failure into a stable reason', function (string $message, string $reason) {
    Http::fake(fn () => throw new ConnectionException($message));

    try {
        (new HttpPageTransport)->get('https://eksempel.no/', 'eksempel.no', '93.184.216.34', 1000, 7);
    } catch (FetchFailed $e) {
        expect($e->reason)->toBe($reason)->and($e->getMessage())->not->toContain('eksempel');

        return;
    }
    throw new RuntimeException('Expected a FetchFailed.');
})->with([
    ['cURL error 28: Operation timed out after 7001 milliseconds', FetchFailed::TIMEOUT],
    ['cURL error 7: Failed to connect to eksempel.no port 443', FetchFailed::CONNECTION],
    ['cURL error 60: SSL certificate problem', FetchFailed::CONNECTION],
]);
