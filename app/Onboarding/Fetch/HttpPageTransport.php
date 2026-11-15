<?php

namespace App\Onboarding\Fetch;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The real transport: Laravel's HTTP client with cURL, pinned to the checked IP with CURLOPT_RESOLVE. The
 * URL keeps the host name, so certificate checks and SNI work, but cURL never asks DNS again, which closes
 * the gap between "checked" and "connected" (DNS rebinding).
 */
final class HttpPageTransport implements PageTransport
{
    public function get(string $url, string $host, string $ip, int $maxBytes, int $timeoutSeconds): TransportResponse
    {
        $address = str_contains($ip, ':') ? "[{$ip}]" : $ip;

        try {
            $response = Http::withOptions([
                'stream' => true,
                'allow_redirects' => false,
                'http_errors' => false,
                'verify' => true,
                'decode_content' => true,
                'connect_timeout' => (int) config('ekko.wizard.fetch.connect_timeout_seconds'),
                'timeout' => $timeoutSeconds,
                'curl' => [CURLOPT_RESOLVE => ["{$host}:443:{$address}"]],
            ])->withHeaders([
                'User-Agent' => (string) config('ekko.wizard.fetch.user_agent'),
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,text/plain;q=0.8',
            ])->get($url);
        } catch (ConnectionException $e) {
            throw FetchFailed::because(str_contains($e->getMessage(), 'timed out') ? FetchFailed::TIMEOUT : FetchFailed::CONNECTION);
        }

        $stream = $response->toPsrResponse()->getBody();
        $body = '';
        $truncated = false;
        try {
            while (! $stream->eof()) {
                $body .= $stream->read(8192);
                if (strlen($body) > $maxBytes) {
                    $truncated = true;
                    $body = substr($body, 0, $maxBytes + 1);
                    break;
                }
            }
        } catch (RuntimeException) {
            throw FetchFailed::because(FetchFailed::TIMEOUT);
        } finally {
            $stream->close();
        }

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
}
