<?php

namespace App\Onboarding\Fetch;

use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The real transport: Laravel's HTTP client on Guzzle's cURL handler, pinned to the checked IP with
 * CURLOPT_RESOLVE. The URL keeps the host name, so certificate checks and SNI work, but cURL never asks DNS
 * again, which closes the gap between "checked" and "connected" (DNS rebinding, ADR 0018).
 *
 * The cURL handler is set explicitly. Guzzle's default stack sends streamed requests to its PHP stream handler,
 * which ignores the cURL options and looks the host up again. A proxy from the environment is turned off too,
 * since it would make the pinning meaningless. The size limit is kept while receiving: a transfer callback stops
 * cURL once more than $maxBytes have arrived. The body is asked for without compression and decoded here with
 * the same limit, so a small compressed answer cannot grow past it.
 */
final class HttpPageTransport implements PageTransport
{
    public function get(string $url, string $host, string $ip, int $maxBytes, int $timeoutSeconds): TransportResponse
    {
        $address = str_contains($ip, ':') ? "[{$ip}]" : $ip;
        $port = parse_url($url, PHP_URL_PORT) ?: 443;
        $exceeded = false;

        try {
            $response = Http::setHandler(new CurlHandler)->withOptions([
                'allow_redirects' => false,
                'http_errors' => false,
                'verify' => true,
                'proxy' => '',
                'decode_content' => false,
                'connect_timeout' => (int) config('ekko.wizard.fetch.connect_timeout_seconds'),
                'timeout' => $timeoutSeconds,
                'curl' => [
                    CURLOPT_RESOLVE => ["{$host}:{$port}:{$address}"],
                    CURLOPT_NOPROGRESS => false,
                    CURLOPT_XFERINFOFUNCTION => self::sizeGuard($maxBytes, $exceeded),
                ],
            ])->withHeaders([
                'User-Agent' => (string) config('ekko.wizard.fetch.user_agent'),
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,text/plain;q=0.8',
                'Accept-Encoding' => 'identity',
            ])->get($url);
        } catch (Throwable $e) {
            if ($exceeded) {
                throw FetchFailed::because(FetchFailed::TOO_LARGE);
            }
            if ($e instanceof ConnectionException) {
                throw FetchFailed::because(self::timedOut($e) ? FetchFailed::TIMEOUT : FetchFailed::CONNECTION);
            }

            throw $e;
        }

        [$body, $truncated] = $this->body($response, $maxBytes);
        $length = $response->header('Content-Length');

        return new TransportResponse(
            $response->status(),
            $response->header('Content-Type') ?: null,
            $response->header('Location') ?: null,
            $body,
            $truncated,
            is_numeric($length) ? (int) $length : null,
        );
    }

    /**
     * Whether cURL gave up on time: error 28 (CURLE_OPERATION_TIMEDOUT) in the handler context of Guzzle's
     * exception, or the message when there is no context (a faked or otherwise wrapped failure).
     */
    private static function timedOut(ConnectionException $e): bool
    {
        $previous = $e->getPrevious();
        if ($previous instanceof ConnectException && isset($previous->getHandlerContext()['errno'])) {
            return (int) $previous->getHandlerContext()['errno'] === CURLE_OPERATION_TIMEDOUT;
        }

        return str_contains($e->getMessage(), 'timed out');
    }

    /**
     * The cURL transfer callback: a non-zero return aborts the transfer once more than $maxBytes have arrived.
     *
     * @return Closure(mixed, int, int, int, int): int
     */
    public static function sizeGuard(int $maxBytes, bool &$exceeded): Closure
    {
        return function ($handle, int $downloadTotal, int $downloaded) use ($maxBytes, &$exceeded): int {
            if ($downloaded > $maxBytes) {
                $exceeded = true;

                return 1;
            }

            return 0;
        };
    }

    /**
     * The body, cut to $maxBytes + 1 bytes. A server that compresses anyway is decoded with the same limit; an
     * answer that cannot be decoded within it counts as cut off.
     *
     * @return array{0: string, 1: bool}
     */
    private function body(Response $response, int $maxBytes): array
    {
        $body = $response->body();
        $encoding = strtolower(trim($response->header('Content-Encoding')));
        if (in_array($encoding, ['gzip', 'x-gzip', 'deflate'], true)) {
            $decoded = @zlib_decode($body, $maxBytes + 1);
            if ($decoded === false) {
                return ['', true];
            }
            $body = $decoded;
        }

        if (strlen($body) > $maxBytes) {
            return [substr($body, 0, $maxBytes + 1), true];
        }

        return [$body, false];
    }
}
