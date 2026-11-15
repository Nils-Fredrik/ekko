<?php

// The real transport against Laravel's HTTP fake: what it asks cURL for, and how it reads the body. The last
// tests make real connections, to show that the pinned address is the one cURL connects to.

use App\Onboarding\Fetch\FetchFailed;
use App\Onboarding\Fetch\HttpPageTransport;
use GuzzleHttp\Exception\ConnectException;
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
        ->and($seen)->not->toHaveKey('stream')
        ->and($seen['proxy'])->toBe('')
        ->and($seen['decode_content'])->toBeFalse()
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

it('reads a timeout from the cURL error number, not from the message', function (int $errno, string $reason) {
    $guzzle = new ConnectException('message without the usual words', new GuzzleHttp\Psr7\Request('GET', 'https://eksempel.no/'), null, ['errno' => $errno]);
    Http::fake(fn () => throw new ConnectionException($guzzle->getMessage(), 0, $guzzle));

    try {
        (new HttpPageTransport)->get('https://eksempel.no/', 'eksempel.no', '93.184.216.34', 1000, 7);
    } catch (FetchFailed $e) {
        expect($e->reason)->toBe($reason);

        return;
    }
    throw new RuntimeException('Expected a FetchFailed.');
})->with([
    'timeout (28)' => [CURLE_OPERATION_TIMEDOUT, FetchFailed::TIMEOUT],
    'refused (7)' => [CURLE_COULDNT_CONNECT, FetchFailed::CONNECTION],
]);

it('takes the port for the pinning from the URL', function () {
    $seen = null;
    Http::fake(function (Request $request, array $options) use (&$seen) {
        $seen = $options;

        return Http::response('x', 200);
    });

    (new HttpPageTransport)->get('https://eksempel.no:8443/', 'eksempel.no', '93.184.216.34', 1000, 7);

    expect($seen['curl'][CURLOPT_RESOLVE])->toBe(['eksempel.no:8443:93.184.216.34']);
});

it('decodes a compressed body within the limit and treats a larger one as cut off', function () {
    Http::fake([
        'eksempel.no/*' => Http::response(gzencode('<p>hei</p>'), 200, ['Content-Encoding' => 'gzip']),
        'stor.no/*' => Http::response(gzencode(str_repeat('a', 50_000)), 200, ['Content-Encoding' => 'gzip']),
    ]);

    $small = (new HttpPageTransport)->get('https://eksempel.no/', 'eksempel.no', '93.184.216.34', 1000, 7);
    $large = (new HttpPageTransport)->get('https://stor.no/', 'stor.no', '93.184.216.34', 20_000, 7);

    expect($small->body)->toBe('<p>hei</p>')->and($small->truncated)->toBeFalse()
        ->and($large->truncated)->toBeTrue()->and(strlen($large->body))->toBeLessThanOrEqual(20_001);
});

it('stops the transfer once more than the limit has arrived', function () {
    $exceeded = false;
    $guard = HttpPageTransport::sizeGuard(1000, $exceeded);

    expect($guard(null, 0, 1000, 0, 0))->toBe(0)->and($exceeded)->toBeFalse()
        ->and($guard(null, 0, 1001, 0, 0))->toBe(1)->and($exceeded)->toBeTrue();
});

it('connects to the pinned address without asking DNS', function () {
    // A host name that can never resolve (.invalid), pinned to a local socket that accepts the connection but
    // never answers the TLS handshake. Through the pinning the connection is made and the read times out; had
    // cURL asked DNS, it would have failed with a connection error instead.
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    expect($server)->not->toBeFalse();
    $port = (int) substr(strrchr((string) stream_socket_get_name($server, false), ':'), 1);

    try {
        (new HttpPageTransport)->get("https://ekko-pinning.invalid:{$port}/", 'ekko-pinning.invalid', '127.0.0.1', 1000, 1);
    } catch (FetchFailed $e) {
        expect($e->reason)->toBe(FetchFailed::TIMEOUT);

        return;
    } finally {
        fclose($server);
    }
    throw new RuntimeException('Expected a FetchFailed.');
});

it('fails with a connection error when the pinned address does not answer', function () {
    // The same host name, without anything listening on the pinned port: cURL connects to 127.0.0.1 and is
    // refused, quickly and without a DNS lookup.
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr((string) stream_socket_get_name($server, false), ':'), 1);
    fclose($server);

    try {
        (new HttpPageTransport)->get("https://ekko-pinning.invalid:{$port}/", 'ekko-pinning.invalid', '127.0.0.1', 1000, 1);
    } catch (FetchFailed $e) {
        expect($e->reason)->toBe(FetchFailed::CONNECTION);

        return;
    }
    throw new RuntimeException('Expected a FetchFailed.');
});
