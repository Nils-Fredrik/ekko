<?php

use App\Models\McpClient;
use App\Models\McpConnection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Support\Mcp\McpTestClient;

// T39-F5: client registration cannot be used to block new Claude connections for everyone, and no page can
// be framed by another site.

function registerClient(string $ip = '203.0.113.10')
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/oauth/register', ['redirect_uris' => [McpTestClient::REDIRECT]]);
}

function usedClient(): McpClient
{
    $client = McpClient::query()->create(['id' => 'ekko-used-'.Str::random(8), 'name' => 'Claude', 'redirect_uris' => [McpTestClient::REDIRECT]]);
    $user = User::factory()->create();
    $tenant = Team::factory()->tenant()->create(['user_id' => $user->id]);
    (new McpConnection)->forceFill([
        'user_id' => $user->id,
        'team_id' => $tenant->id,
        'client_id' => $client->id,
        'client_name' => 'Claude',
        'redirect_host' => 'claude.ai',
        'abilities' => [],
    ])->save();

    return $client;
}

test('registration is limited per address per day, and another address can still register', function () {
    config(['ekko.mcp.clients_per_ip_per_day' => 3]);

    foreach (range(1, 3) as $_) {
        registerClient()->assertCreated();
    }

    registerClient()->assertStatus(429);
    registerClient('198.51.100.20')->assertCreated();
});

test('the ceiling counts only unused clients, so clients in use never block new ones', function () {
    config(['ekko.mcp.unused_clients_max' => 2]);
    usedClient();
    usedClient();
    usedClient();

    registerClient('203.0.113.1')->assertCreated();
    registerClient('203.0.113.2')->assertCreated();

    registerClient('203.0.113.3')->assertStatus(429)->assertJson(['error' => 'temporarily_unavailable']);
});

test('unused clients are deleted after an hour, so a full ceiling blocks for an hour at most', function () {
    config(['ekko.mcp.unused_clients_max' => 2]);
    $used = usedClient();
    $used->forceFill(['created_at' => now()->subDays(3)])->save();
    registerClient('203.0.113.1')->assertCreated();
    registerClient('203.0.113.2')->assertCreated();
    registerClient('203.0.113.3')->assertStatus(429);

    $this->travel(61)->minutes();

    registerClient('203.0.113.4')->assertCreated();
    expect(McpClient::query()->unused()->count())->toBe(1)
        ->and(McpClient::query()->find($used->id))->not->toBeNull();
});

test('no response may be framed by another site', function (string $method, string $uri) {
    $this->json($method, $uri)
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");
})->with([
    'login page' => ['GET', '/login'],
    'consent page, redirect to login' => ['GET', '/oauth/authorize'],
    'registration' => ['POST', '/oauth/register'],
    'not found' => ['GET', '/does-not-exist'],
]);

test('the consent page itself cannot be framed', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $tenant = Team::factory()->tenant()->create(['user_id' => $user->id]);
    $user->switchTeam($tenant);
    $client = registerClient()->json('client_id');

    $query = [
        'response_type' => 'code',
        'client_id' => $client,
        'redirect_uri' => McpTestClient::REDIRECT,
        'code_challenge' => McpTestClient::challenge(),
        'code_challenge_method' => 'S256',
        'state' => 'state-123',
        'scope' => 'ekko:read',
        'resource' => url('/mcp'),
    ];

    $this->actingAs($user)->get('/oauth/authorize?'.http_build_query($query))
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");
});
