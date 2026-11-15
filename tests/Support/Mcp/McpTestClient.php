<?php

namespace Tests\Support\Mcp;

use App\Mcp\McpTokens;
use App\Models\McpClient;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Connects a user to the MCP server the way Claude does (a registered client, a code from the consent page,
 * the token exchange with PKCE) and calls tools over HTTP, through the whole middleware stack.
 */
final class McpTestClient
{
    public const VERIFIER = 'test-verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

    public const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';

    public static function challenge(string $verifier = self::VERIFIER): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public static function client(): McpClient
    {
        return McpClient::query()->create([
            'id' => 'ekko-'.Str::lower(Str::random(40)),
            'name' => 'Claude',
            'redirect_uris' => [self::REDIRECT],
        ]);
    }

    /** An access token for a new connection of the user to the tenant. */
    public static function connect(User $user, Team $tenant, bool $write = false): string
    {
        $tokens = app(McpTokens::class);
        $client = self::client();
        $code = $tokens->issueCode($user, $tenant, $client, self::REDIRECT, self::challenge(),
            $write ? [McpTokens::READ, McpTokens::WRITE] : [McpTokens::READ]);

        return (string) $tokens->exchangeCode($code, $client->id, self::REDIRECT, self::VERIFIER)['access_token'];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public static function rpc(TestCase $test, ?string $token, string $method, array $params = []): TestResponse
    {
        $headers = ['Accept' => 'application/json, text/event-stream'];
        if ($token !== null) {
            $headers['Authorization'] = "Bearer {$token}";
        }

        return $test->withHeaders($headers)->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ]);
    }

    /**
     * Calls a tool and returns the result: isError, the text of the first content block and the structured content.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{isError: bool, text: string, data: array<string, mixed>|null, raw: string}
     */
    public static function call(TestCase $test, string $token, string $tool, array $arguments = []): array
    {
        $response = self::rpc($test, $token, 'tools/call', ['name' => $tool, 'arguments' => (object) $arguments]);
        $response->assertOk();
        $result = $response->json('result');
        if (! is_array($result)) {
            throw new \RuntimeException('No result: '.$response->getContent());
        }

        return [
            'isError' => (bool) ($result['isError'] ?? false),
            'text' => (string) ($result['content'][0]['text'] ?? ''),
            'data' => $result['structuredContent'] ?? null,
            'raw' => (string) $response->getContent(),
        ];
    }

    /**
     * The names of the tools the connection lists.
     *
     * @return list<string>
     */
    public static function tools(TestCase $test, string $token): array
    {
        return array_column((array) self::rpc($test, $token, 'tools/list')->assertOk()->json('result.tools'), 'name');
    }
}
