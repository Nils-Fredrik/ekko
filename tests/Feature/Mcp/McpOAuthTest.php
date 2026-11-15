<?php

use App\Mcp\McpTokens;
use App\Models\McpConnection;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\ActingAs;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\Mcp\McpTestClient;

// docs/spec.md 15.2 (T34), ADR 0016: Claude connects with OAuth 2.1 the way it does in production: discovery,
// dynamic client registration, consent with the ordinary login, the token exchange with PKCE, calls to /mcp,
// refresh and disconnecting.

beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->tenant = Team::factory()->tenant()->create(['user_id' => $this->user->id, 'name' => 'Fjordkaffe AS']);
    $this->user->switchTeam($this->tenant);
});

/**
 * @return array<string, string>
 */
function authorizeQuery(string $clientId, array $extra = []): array
{
    return [
        'response_type' => 'code',
        'client_id' => $clientId,
        'redirect_uri' => McpTestClient::REDIRECT,
        'code_challenge' => McpTestClient::challenge(),
        'code_challenge_method' => 'S256',
        'state' => 'state-123',
        'scope' => 'ekko:read ekko:write',
        'resource' => url('/mcp'),
        ...$extra,
    ];
}

test('the whole flow: discovery, registration, consent, token, a tool call, refresh and revoke', function () {
    // Without a token: 401 with the address of the metadata.
    $unauthenticated = McpTestClient::rpc($this, null, 'tools/list');
    $unauthenticated->assertUnauthorized();
    expect($unauthenticated->headers->get('WWW-Authenticate'))->toContain('resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"');

    $resource = $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertOk();
    expect($resource->json('resource'))->toBe(url('/mcp'))
        ->and($resource->json('authorization_servers'))->toBe([url('/')]);
    $server = $this->getJson('/.well-known/oauth-authorization-server')->assertOk();
    expect($server->json('code_challenge_methods_supported'))->toBe(['S256'])
        ->and($server->json('registration_endpoint'))->toBe(route('mcp.oauth.register'));

    $clientId = $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [McpTestClient::REDIRECT]])
        ->assertCreated()->json('client_id');

    $this->actingAs($this->user)->get('/oauth/authorize?'.http_build_query(authorizeQuery($clientId)))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Mcp/Authorize')
            ->where('client', 'Claude')
            ->where('writeRequested', true)
            ->where('tenants.0.name', 'Fjordkaffe AS')
            ->where('defaultTenant', $this->tenant->id));

    $redirect = $this->post('/oauth/authorize', [...authorizeQuery($clientId), 'decision' => 'approve', 'tenant' => $this->tenant->id, 'allow_write' => '1'])
        ->assertRedirect();
    parse_str((string) parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $callback);
    expect($callback['state'])->toBe('state-123')->and($callback['iss'])->toBe(url('/'));

    auth()->forgetGuards();
    $tokens = $this->post('/oauth/token', [
        'grant_type' => 'authorization_code', 'code' => $callback['code'], 'client_id' => $clientId,
        'redirect_uri' => McpTestClient::REDIRECT, 'code_verifier' => McpTestClient::VERIFIER,
    ])->assertOk()->json();
    expect($tokens['token_type'])->toBe('Bearer')->and($tokens['scope'])->toBe('ekko:read ekko:write');

    $whoami = McpTestClient::call($this, $tokens['access_token'], 'whoami');
    expect($whoami['data']['can_write'])->toBeTrue()
        ->and($whoami['data']['tenants'][0]['id'])->toBe($this->tenant->id);

    // Refresh: a new pair, the old refresh token and the old access token stop working.
    $renewed = $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $clientId])
        ->assertOk()->json();
    $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $clientId])
        ->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
    McpTestClient::rpc($this, $tokens['access_token'], 'tools/list')->assertUnauthorized();
    McpTestClient::rpc($this, $renewed['access_token'], 'tools/list')->assertOk();

    // Revoke: the connection ends at once.
    $this->post('/oauth/revoke', ['token' => $renewed['refresh_token'], 'client_id' => $clientId])->assertOk();
    McpTestClient::rpc($this, $renewed['access_token'], 'tools/list')->assertUnauthorized();
    expect(McpConnection::query()->sole()->revoked_at)->not->toBeNull();
});

test('registration only accepts Claude\'s callback addresses', function () {
    $this->postJson('/oauth/register', ['redirect_uris' => ['https://evil.example/callback']])
        ->assertStatus(400)->assertJson(['error' => 'invalid_redirect_uri']);
    $this->postJson('/oauth/register', ['redirect_uris' => ['http://localhost:51234/callback']])->assertCreated();
});

test('the consent page refuses an unknown client or a callback the client did not register', function () {
    $client = McpTestClient::client();

    $this->actingAs($this->user)->get('/oauth/authorize?'.http_build_query(authorizeQuery('ekko-unknown')))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('error', __('mcp.authorize.unknown_client')));
    $this->actingAs($this->user)->get('/oauth/authorize?'.http_build_query(authorizeQuery($client->id, ['redirect_uri' => 'https://claude.com/api/mcp/auth_callback'])))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('error', __('mcp.authorize.bad_redirect')));
});

test('denying sends access_denied back, and a tenant the user cannot select is refused', function () {
    $client = McpTestClient::client();
    $other = Team::factory()->tenant()->create();

    $denied = $this->actingAs($this->user)->post('/oauth/authorize', [...authorizeQuery($client->id), 'decision' => 'deny', 'tenant' => $this->tenant->id]);
    $foreign = $this->actingAs($this->user)->post('/oauth/authorize', [...authorizeQuery($client->id), 'decision' => 'approve', 'tenant' => $other->id]);

    expect($denied->headers->get('Location'))->toContain('error=access_denied');
    $foreign->assertInertia(fn (AssertableInertia $page) => $page->where('error', __('mcp.authorize.no_tenant')));
    expect(McpConnection::query()->count())->toBe(0);
});

test('without allowing changes the connection is read only, even when Claude asked for write', function () {
    $client = McpTestClient::client();
    $redirect = $this->actingAs($this->user)->post('/oauth/authorize', [...authorizeQuery($client->id), 'decision' => 'approve', 'tenant' => $this->tenant->id, 'allow_write' => '0']);
    parse_str((string) parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $callback);

    $tokens = app(McpTokens::class)->exchangeCode($callback['code'], $client->id, McpTestClient::REDIRECT, McpTestClient::VERIFIER);

    expect($tokens['scope'])->toBe('ekko:read');
});

test('a code works once, needs the right verifier, and reuse disconnects what it gave', function () {
    $tokens = app(McpTokens::class);
    $client = McpTestClient::client();
    $code = $tokens->issueCode($this->user, $this->tenant, $client, McpTestClient::REDIRECT, McpTestClient::challenge(), [McpTokens::READ]);
    $wrong = $tokens->issueCode($this->user, $this->tenant, $client, McpTestClient::REDIRECT, McpTestClient::challenge(), [McpTokens::READ]);

    expect($tokens->exchangeCode($wrong, $client->id, McpTestClient::REDIRECT, str_repeat('x', 50)))->toBeNull();
    $first = $tokens->exchangeCode($code, $client->id, McpTestClient::REDIRECT, McpTestClient::VERIFIER);
    expect($first)->not->toBeNull()
        ->and($tokens->exchangeCode($code, $client->id, McpTestClient::REDIRECT, McpTestClient::VERIFIER))->toBeNull();
    McpTestClient::rpc($this, $first['access_token'], 'tools/list')->assertUnauthorized();
});

test('an expired code or access token is refused', function () {
    $tokens = app(McpTokens::class);
    $client = McpTestClient::client();
    $code = $tokens->issueCode($this->user, $this->tenant, $client, McpTestClient::REDIRECT, McpTestClient::challenge(), [McpTokens::READ]);
    $token = McpTestClient::connect($this->user, $this->tenant);

    Date::setTestNow(now()->addMinutes(61));
    expect($tokens->exchangeCode($code, $client->id, McpTestClient::REDIRECT, McpTestClient::VERIFIER))->toBeNull();
    McpTestClient::rpc($this, $token, 'tools/list')->assertUnauthorized();
    Date::setTestNow();
});

test('ordinary API tokens do not work on /mcp, and Claude tokens are not listed as API tokens', function () {
    $apiToken = $this->user->createToken('My token', ['read', 'write'])->plainTextToken;
    McpTestClient::connect($this->user, $this->tenant);

    McpTestClient::rpc($this, $apiToken, 'tools/list')->assertUnauthorized();
    expect($this->user->tokens()->pluck('name')->all())->toBe(['My token'])
        ->and(PersonalAccessToken::query()->where('name', McpTokens::TOKEN_NAME)->count())->toBe(1);
});

test('a user who must use two-factor authentication is refused until it is confirmed', function () {
    $partner = Team::factory()->tenant()->create();
    $advisor = User::factory()->create();
    $partner->users()->attach($advisor, ['role' => 'partner_advisor']);

    McpTestClient::rpc($this, McpTestClient::connect($advisor, $partner), 'tools/list')->assertForbidden();
});

test('a user who lost access to the tenant gets no tools that reach it', function () {
    $member = User::factory()->create();
    $this->tenant->users()->attach($member, ['role' => 'editor']);
    $token = McpTestClient::connect($member, $this->tenant);
    $this->tenant->users()->detach($member);

    $result = McpTestClient::call($this, $token, 'list_brands');

    expect($result['isError'])->toBeTrue()->and($result['text'])->toBe(__('mcp.errors.unknown_tenant'));
});

test('consent is refused while a platform admin acts as a tenant', function () {
    $admin = User::factory()->platformAdmin()->withTwoFactor()->create();
    $client = McpTestClient::client();
    $this->actingAs($admin);
    app(ActingAs::class)->start($admin, $this->tenant, 'Support');

    $this->get('/oauth/authorize?'.http_build_query(authorizeQuery($client->id)))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('error', __('mcp.authorize.acting_as')));
});

test('the connection page lists only the user\'s own connections, and only the owner can disconnect', function () {
    McpTestClient::connect($this->user, $this->tenant);
    $stranger = User::factory()->withPersonalTeam()->create();
    $strangerTeam = Team::factory()->tenant()->create(['user_id' => $stranger->id]);
    McpTestClient::connect($stranger, $strangerTeam);
    [$own, $foreign] = McpConnection::query()->orderBy('id')->get()->all();

    $this->actingAs($this->user)->get('/claude')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Mcp/Connections')
            ->has('connections', 1)
            ->where('connections.0.id', $own->id)
            ->where('connections.0.tenant', 'Fjordkaffe AS'));
    $this->actingAs($this->user)->delete("/claude/{$foreign->id}")->assertNotFound();
    $this->actingAs($this->user)->delete("/claude/{$own->id}")->assertRedirect();

    expect($own->fresh()->revoked_at)->not->toBeNull()
        ->and($foreign->fresh()->revoked_at)->toBeNull();
});

test('a Claude token is never accepted outside /mcp: not on the consent page, the pages or the API', function () {
    $token = McpTestClient::connect($this->user, $this->tenant);
    $client = McpTestClient::client();
    $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

    $this->withHeaders($headers)->getJson('/api/user')->assertUnauthorized();
    $this->withHeaders($headers)->getJson('/claude')->assertUnauthorized();
    $this->withHeaders($headers)->getJson('/oauth/authorize?'.http_build_query(authorizeQuery($client->id)))->assertUnauthorized();
    $this->withHeaders($headers)->postJson('/oauth/authorize', [...authorizeQuery($client->id), 'decision' => 'approve', 'tenant' => $this->tenant->id, 'allow_write' => '1'])
        ->assertUnauthorized();
    // Ordinary API tokens still work there.
    $this->withHeaders(['Authorization' => 'Bearer '.$this->user->createToken('API', ['read'])->plainTextToken])->getJson('/api/user')->assertOk();
    expect(McpConnection::query()->count())->toBe(1);
});

test('a reused code disconnects only the connection it gave', function () {
    $tokens = app(McpTokens::class);
    $client = McpTestClient::client();
    $code = $tokens->issueCode($this->user, $this->tenant, $client, McpTestClient::REDIRECT, McpTestClient::challenge(), [McpTokens::READ]);
    $first = $tokens->exchangeCode($code, $client->id, McpTestClient::REDIRECT, McpTestClient::VERIFIER);
    // A later, independent connection with the same client.
    $second = $tokens->exchangeCode(
        $tokens->issueCode($this->user, $this->tenant, $client, McpTestClient::REDIRECT, McpTestClient::challenge(), [McpTokens::READ]),
        $client->id, McpTestClient::REDIRECT, McpTestClient::VERIFIER,
    );

    expect($tokens->exchangeCode($code, $client->id, McpTestClient::REDIRECT, McpTestClient::VERIFIER))->toBeNull();
    McpTestClient::rpc($this, $first['access_token'], 'tools/list')->assertUnauthorized();
    McpTestClient::rpc($this, $second['access_token'], 'tools/list')->assertOk();
});
