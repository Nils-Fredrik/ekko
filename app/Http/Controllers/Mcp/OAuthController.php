<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Mcp\McpRedirects;
use App\Mcp\McpTokens;
use App\Models\McpClient;
use App\Models\McpConnection;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\ActingAs;
use App\Tenancy\TenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The authorization server for the Claude connection (docs/spec.md 15.2, ADR 0016), built like Varde's and after
 * Claude's requirements: metadata (RFC 9728 and RFC 8414), dynamic client registration (RFC 7591) for public
 * clients, authorization code with PKCE S256, rotated refresh tokens, and revocation (RFC 7009). Login is the
 * ordinary one (with two-factor authentication where required). On the consent page the user picks the tenant
 * and whether Claude may also make changes.
 */
class OAuthController extends Controller
{
    public const SCOPES = [McpTokens::SCOPE_READ, McpTokens::SCOPE_WRITE];

    private const PASSED_PARAMS = ['response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource'];

    public function __construct(private readonly McpTokens $tokens) {}

    /** Protected Resource Metadata (RFC 9728) for /mcp. */
    public function protectedResource(): JsonResponse
    {
        return response()->json([
            'resource' => url('/mcp'),
            'authorization_servers' => [url('/')],
            'scopes_supported' => self::SCOPES,
            'bearer_methods_supported' => ['header'],
            'resource_name' => config('app.name'),
        ]);
    }

    /** Authorization Server Metadata (RFC 8414). */
    public function authorizationServer(): JsonResponse
    {
        return response()->json([
            'issuer' => url('/'),
            'authorization_endpoint' => route('mcp.oauth.authorize'),
            'token_endpoint' => route('mcp.oauth.token'),
            'registration_endpoint' => route('mcp.oauth.register'),
            'revocation_endpoint' => route('mcp.oauth.revoke'),
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'revocation_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => self::SCOPES,
            'authorization_response_iss_parameter_supported' => true,
        ]);
    }

    /** Dynamic client registration (RFC 7591). Only Claude's callback addresses; every client is public. */
    public function register(Request $request): JsonResponse
    {
        $uris = $request->json('redirect_uris');
        if (! is_array($uris) || $uris === [] || count($uris) > 10) {
            return $this->registrationError('invalid_redirect_uri', __('mcp.oauth.redirect_list'));
        }
        foreach ($uris as $uri) {
            if (! is_string($uri) || ! McpRedirects::allowed($uri)) {
                return $this->registrationError('invalid_redirect_uri', __('mcp.oauth.redirect_not_claude'));
            }
        }
        $grants = $request->json('grant_types', ['authorization_code']);
        if (! is_array($grants) || array_diff($grants, ['authorization_code', 'refresh_token']) !== []) {
            return $this->registrationError('invalid_client_metadata', __('mcp.oauth.grants'));
        }
        $responses = $request->json('response_types', ['code']);
        if (! is_array($responses) || array_diff($responses, ['code']) !== []) {
            return $this->registrationError('invalid_client_metadata', __('mcp.oauth.response_types'));
        }
        $name = $request->json('client_name');
        $name = is_string($name) && trim($name) !== '' ? Str::limit(strip_tags(trim($name)), 200, '') : 'Claude';

        // A ceiling for the whole application, not only per address: many addresses could fill mcp_clients.
        if (McpClient::query()->where('created_at', '>=', now()->subDay())->count() >= (int) config('ekko.mcp.clients_per_day')) {
            return response()->json(
                ['error' => 'temporarily_unavailable', 'error_description' => __('mcp.oauth.too_many_clients')],
                429,
                ['Cache-Control' => 'no-store', 'Retry-After' => '3600'],
            );
        }

        $client = McpClient::query()->create([
            'id' => 'ekko-'.Str::lower(Str::random(40)),
            'name' => $name,
            'redirect_uris' => array_values(array_unique($uris)),
        ]);

        return response()->json([
            'client_id' => $client->id,
            'client_id_issued_at' => $client->created_at?->getTimestamp(),
            'client_name' => $client->name,
            'redirect_uris' => $client->redirect_uris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'scope' => implode(' ', self::SCOPES),
        ], 201, ['Cache-Control' => 'no-store']);
    }

    /**
     * The consent page: who connects, which tenant Claude works in, the role there and whether Claude may make
     * changes. Errors in the client or callback are shown here; other errors go back to Claude (RFC 6749 4.1.2.1).
     */
    public function authorize(Request $request, TenantAccess $access, ActingAs $actingAs): Response|RedirectResponse
    {
        [$client, $error] = $this->clientFor($request->query());
        if ($client === null) {
            return $this->errorPage((string) $error);
        }
        $params = $this->authorizationParams($request->query());
        if (is_string($params)) {
            return $this->redirectError((string) $request->query('redirect_uri'), $params, $request->query('state'));
        }
        if ($actingAs->state() !== null) {
            return $this->errorPage(__('mcp.authorize.acting_as'));
        }

        /** @var User $user */
        $user = $request->user();
        $tenants = $this->selectableTenants($user, $access);
        if ($tenants === []) {
            return $this->errorPage(__('mcp.authorize.no_tenant'));
        }

        return Inertia::render('Mcp/Authorize', [
            'client' => $client->name,
            'host' => McpRedirects::host($params['redirect_uri']),
            'loopback' => McpRedirects::isLoopback($params['redirect_uri']),
            'user' => ['name' => $user->name, 'email' => $user->email],
            'tenants' => $tenants,
            'defaultTenant' => collect($tenants)->firstWhere('id', $user->current_team_id)['id'] ?? $tenants[0]['id'],
            'writeRequested' => $params['write'],
            'params' => Arr::only($request->query(), self::PASSED_PARAMS),
            'csrf' => csrf_token(),
        ]);
    }

    /** The answer on the consent page: yes gives a one-time code, no gives access_denied. Both go to the client. */
    public function decide(Request $request, TenantAccess $access, ActingAs $actingAs): Response|RedirectResponse
    {
        [$client, $error] = $this->clientFor($request->input());
        if ($client === null) {
            return $this->errorPage((string) $error);
        }
        $params = $this->authorizationParams($request->input());
        if (is_string($params)) {
            return $this->redirectError((string) $request->input('redirect_uri'), $params, $request->input('state'));
        }
        if ($actingAs->state() !== null) {
            return $this->errorPage(__('mcp.authorize.acting_as'));
        }
        if ($request->input('decision') !== 'approve') {
            return $this->redirectError($params['redirect_uri'], 'access_denied', $params['state']);
        }

        /** @var User $user */
        $user = $request->user();
        $tenant = Team::query()->find((int) $request->input('tenant'));
        if ($tenant === null || $tenant->personal_team || ! $access->canSelect($user, $tenant)) {
            return $this->errorPage(__('mcp.authorize.no_tenant'));
        }

        $write = $request->boolean('allow_write') && $params['write'];
        $code = $this->tokens->issueCode($user, $tenant, $client, $params['redirect_uri'], $params['code_challenge'],
            $write ? [McpTokens::READ, McpTokens::WRITE] : [McpTokens::READ]);

        return redirect()->away($this->withQuery($params['redirect_uri'], array_filter([
            'code' => $code,
            'state' => $params['state'],
            'iss' => url('/'),
        ], fn ($value) => $value !== null)));
    }

    /** The token endpoint: authorization_code (with PKCE) and refresh_token, as form data. */
    public function token(Request $request): JsonResponse
    {
        $client = $this->resolveClient($this->clientIdFrom($request));
        if ($client === null) {
            // Claude registers the client again on invalid_client (401).
            return $this->tokenError('invalid_client', 401);
        }

        $refresh = $request->input('refresh_token');
        $result = match ($request->input('grant_type')) {
            'authorization_code' => $this->exchange($request, $client),
            'refresh_token' => is_string($refresh) ? ($this->tokens->refresh($refresh, $client->id) ?? 'invalid_grant') : 'invalid_request',
            default => 'unsupported_grant_type',
        };
        if (is_string($result)) {
            return $this->tokenError($result);
        }

        return response()->json(['token_type' => 'Bearer', ...$result], 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    /** Revocation (RFC 7009): refresh token or access token. Always answers 200. */
    public function revoke(Request $request): JsonResponse
    {
        $token = (string) $request->input('token');
        if ($token !== '') {
            $connection = McpConnection::query()->active()->where('refresh_token_hash', McpTokens::hash($token))->first()
                ?? $this->tokens->connectionFor(PersonalAccessToken::findToken($token));
            $clientId = $this->clientIdFrom($request);
            if ($connection !== null && ($clientId === null || $connection->client_id === $clientId)) {
                $this->tokens->revoke($connection);
            }
        }

        return response()->json([], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * The tenants the user can choose: those in the tenant switcher (memberships and partner clients), without
     * personal teams, which hold no monitoring data.
     *
     * @return list<array{id: int, name: string, role: string|null, clients: int}>
     */
    private function selectableTenants(User $user, TenantAccess $access): array
    {
        return $access->accessibleTenants($user)
            ->reject(fn (Team $team): bool => (bool) $team->personal_team)
            ->map(fn (Team $team): array => [
                'id' => $team->id,
                'name' => $team->name,
                'role' => $access->roleIn($user, $team)?->value,
                // A partner advisor working in the partner tenant also reaches its clients.
                'clients' => count($access->rlsTenantIds($user, $team)) - 1,
            ])->values()->all();
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int, scope: string}|string
     */
    private function exchange(Request $request, McpClient $client): array|string
    {
        $code = $request->input('code');
        $redirect = $request->input('redirect_uri');
        $verifier = $request->input('code_verifier');
        if (! is_string($code) || ! is_string($redirect) || ! is_string($verifier)) {
            return 'invalid_request';
        }

        return $this->tokens->exchangeCode($code, $client->id, $redirect, $verifier) ?? 'invalid_grant';
    }

    /**
     * The client and the callback. Errors here are shown on the page and never sent to the callback.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: ?McpClient, 1: ?string}
     */
    private function clientFor(array $input): array
    {
        $client = $this->resolveClient(is_string($input['client_id'] ?? null) ? $input['client_id'] : null);
        if ($client === null) {
            return [null, __('mcp.authorize.unknown_client')];
        }
        $redirect = $input['redirect_uri'] ?? null;
        if (! is_string($redirect) || ! McpRedirects::allowed($redirect) || ! McpRedirects::matches($redirect, $client->redirect_uris)) {
            return [null, __('mcp.authorize.bad_redirect')];
        }

        return [$client, null];
    }

    private function resolveClient(?string $id): ?McpClient
    {
        return $id !== null && str_starts_with($id, 'ekko-') ? McpClient::query()->find($id) : null;
    }

    /**
     * The rest of the request, or the error code (RFC 6749) when something is missing.
     *
     * @param  array<string, mixed>  $input
     * @return array{redirect_uri: string, code_challenge: string, state: ?string, write: bool}|string
     */
    private function authorizationParams(array $input): array|string
    {
        if (($input['response_type'] ?? null) !== 'code') {
            return 'unsupported_response_type';
        }
        $challenge = $input['code_challenge'] ?? null;
        if (($input['code_challenge_method'] ?? null) !== 'S256' || ! is_string($challenge) || preg_match('/^[A-Za-z0-9\-_]{43,128}$/', $challenge) !== 1) {
            return 'invalid_request';
        }
        $resource = $input['resource'] ?? null;
        if ($resource !== null && rtrim((string) $resource, '/') !== url('/mcp')) {
            return 'invalid_target';
        }
        $requested = array_filter(explode(' ', (string) ($input['scope'] ?? '')));
        // Without a scope both apply; the user chooses on the page.
        $scopes = $requested === [] ? self::SCOPES : array_values(array_intersect($requested, self::SCOPES));
        if ($scopes === []) {
            return 'invalid_scope';
        }
        $state = $input['state'] ?? null;

        return [
            'redirect_uri' => (string) $input['redirect_uri'],
            'code_challenge' => $challenge,
            'state' => is_string($state) ? $state : null,
            'write' => in_array(McpTokens::SCOPE_WRITE, $scopes, true),
        ];
    }

    private function clientIdFrom(Request $request): ?string
    {
        $id = $request->input('client_id');
        if (is_string($id) && $id !== '') {
            return $id;
        }
        // client_secret_basic with an empty secret: client_id in the Basic header.
        $user = $request->getUser();

        return is_string($user) && $user !== '' ? urldecode($user) : null;
    }

    private function errorPage(string $message): Response
    {
        return Inertia::render('Mcp/Authorize', ['error' => $message]);
    }

    private function redirectError(string $redirectUri, string $error, mixed $state): RedirectResponse
    {
        return redirect()->away($this->withQuery($redirectUri, array_filter([
            'error' => $error,
            'state' => is_string($state) ? $state : null,
            'iss' => url('/'),
        ], fn ($value) => $value !== null)));
    }

    private function registrationError(string $error, string $description): JsonResponse
    {
        return response()->json(['error' => $error, 'error_description' => $description], 400, ['Cache-Control' => 'no-store']);
    }

    private function tokenError(string $error, int $status = 400): JsonResponse
    {
        return response()->json(['error' => $error], $status, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    /** @param  array<string, string>  $params */
    private function withQuery(string $uri, array $params): string
    {
        return $uri.(str_contains($uri, '?') ? '&' : '?').http_build_query($params);
    }
}
