<?php

namespace Tests\Support\Extraction;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 transporter for the Anthropic SDK that answers from canned bodies and keeps the requests, so the
 * real client class is tested without a network and without a key.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @param  array<string, array{0: int, 1: string, 2?: string}>  $routes  "METHOD /path" => [status, body, content type] */
    public function __construct(private readonly array $routes) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $route = $this->routes[$request->getMethod().' '.$request->getUri()->getPath()] ?? [404, '{"type":"error","error":{"type":"not_found_error","message":"no route"}}'];

        return new Response($route[0], ['Content-Type' => $route[2] ?? 'application/json'], $route[1]);
    }

    /** @return array<string, mixed> */
    public function jsonBody(int $index): array
    {
        return json_decode((string) $this->requests[$index]->getBody(), true);
    }

    public static function message(string $text, string $stopReason = 'end_turn', int $input = 11, int $output = 7): string
    {
        return json_encode([
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => $stopReason, 'stop_sequence' => null,
            'usage' => ['input_tokens' => $input, 'output_tokens' => $output],
        ], JSON_THROW_ON_ERROR);
    }

    public static function batch(string $status): string
    {
        return json_encode([
            'id' => 'msgbatch_1', 'type' => 'message_batch', 'processing_status' => $status,
            'request_counts' => ['processing' => 0, 'succeeded' => 1, 'errored' => 0, 'canceled' => 0, 'expired' => 0],
            'ended_at' => null, 'created_at' => '2026-11-15T10:00:00Z', 'expires_at' => '2026-11-16T10:00:00Z',
            'archived_at' => null, 'cancel_initiated_at' => null, 'results_url' => null,
        ], JSON_THROW_ON_ERROR);
    }
}
