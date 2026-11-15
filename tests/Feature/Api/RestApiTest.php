<?php

use App\Models\Activity;
use App\Models\AggDaily;
use App\Models\AggSourceDaily;
use App\Models\Brand;
use App\Models\Market;
use App\Models\Plan;
use App\Models\Prompt;
use App\Models\Response;
use App\Models\Run;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Mcp\McpTestClient;

function apiCall($test, string $token, string $method, string $url, array $data = [])
{
    Auth::forgetGuards();
    Auth::shouldUse('web');

    return $test->json($method, $url, $data, ['Authorization' => 'Bearer '.$token]);
}

beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->ownedTeams()->firstOrFail();
    $this->user->switchTeam($this->team);
    $this->context = app(TenantContext::class);
    $this->brand = $this->context->run($this->team->id, fn () => Brand::factory()->create());
    $this->market = $this->context->run($this->team->id, fn () => Market::factory()->create(['brand_id' => $this->brand->id]));
    $this->token = $this->user->createToken('test', ['read', 'write', 'tenant:'.$this->team->id]);
    $this->path = '/api/v1/brands/'.$this->brand->id;
    Cache::flush();
});

test('Jetstream binds a token to the selected tenant and permission edits cannot rebind it', function () {
    $this->actingAs($this->user)->post('/user/api-tokens', ['name' => 'Jetstream', 'permissions' => ['read', 'tenant:99999', '*']])->assertRedirect();
    $token = $this->user->tokens()->where('name', 'Jetstream')->firstOrFail();
    expect($token->abilities)->toBe(['read', 'tenant:'.$this->team->id]);
    $other = Team::factory()->create(['user_id' => $this->user->id]);
    $this->user->switchTeam($other);
    $this->put('/user/api-tokens/'.$token->id, ['permissions' => ['write', 'tenant:'.$other->id]])->assertRedirect();
    expect($token->fresh()->abilities)->toBe(['write', 'tenant:'.$this->team->id]);
    $this->get('/user/api-tokens')->assertOk()->assertInertia(fn ($page) => $page
        ->where('createTenantName', $other->name)
        ->where('tokens.0.tenant_name', $this->team->name)
        ->where('tokens.1.tenant_name', $this->team->name));
});

test('tenant binding rejects missing malformed duplicate and wildcard-only scopes', function (array $abilities) {
    $this->token->accessToken->forceFill(['abilities' => $abilities])->save();
    apiCall($this, $this->token->plainTextToken, 'GET', '/api/v1/brands')->assertForbidden();
})->with([[['read']], [['*']], [['read', 'tenant:0']], [['read', 'tenant:1', 'tenant:2']], [['read', 'tenant:999999999999999999999999']]]);

test('v1 requires a real token and read ability', function () {
    $this->getJson('/api/v1/brands')->assertUnauthorized();
    $this->actingAs($this->user)->getJson('/api/v1/brands')->assertForbidden();
    $write = $this->user->createToken('write', ['write', 'tenant:'.$this->team->id]);
    apiCall($this, $write->plainTextToken, 'GET', '/api/v1/brands')->assertForbidden();
    apiCall($this, $this->token->plainTextToken, 'GET', '/api/v1/brands')->assertOk()->assertJsonPath('data.0.id', $this->brand->id);
});

test('REST API and MCP tokens cannot authenticate on the other interface', function () {
    $mcpToken = McpTestClient::connect($this->user, $this->team);

    apiCall($this, $mcpToken, 'GET', '/api/v1/brands')->assertUnauthorized();
    apiCall($this, $this->token->plainTextToken, 'GET', '/api/v1/brands')->assertOk();
    McpTestClient::rpc($this, $this->token->plainTextToken, 'tools/list')->assertUnauthorized();
    McpTestClient::rpc($this, $mcpToken, 'tools/list')->assertOk();
});

test('token tenant is independent of browser selection and permission is rechecked after removal', function () {
    $other = Team::factory()->create(['user_id' => $this->user->id]);
    $this->user->switchTeam($other);
    apiCall($this, $this->token->plainTextToken, 'GET', '/api/v1/brands')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->brand->id);
    $member = User::factory()->create();
    $this->team->users()->attach($member, ['role' => 'reader']);
    $token = $member->createToken('member', ['read', 'tenant:'.$this->team->id]);
    apiCall($this, $token->plainTextToken, 'GET', '/api/v1/brands')->assertOk();
    $this->team->users()->detach($member);
    apiCall($this, $token->plainTextToken, 'GET', '/api/v1/brands')->assertForbidden();
});

test('closed tenants and unverified users are refused', function () {
    $this->user->forceFill(['email_verified_at' => null])->save();
    apiCall($this, $this->token->plainTextToken, 'GET', '/api/v1/brands')->assertForbidden();
    $this->user->forceFill(['email_verified_at' => now()])->save();
    $this->team->forceFill(['status' => 'closed'])->save();
    apiCall($this, $this->token->plainTextToken, 'GET', '/api/v1/brands')->assertForbidden();
});

test('prompt writes require both token ability and role and are audited without text', function () {
    $data = ['market_id' => $this->market->id, 'text' => 'Synthetic api test?', 'intent' => 'category', 'funnel_stage' => 'awareness'];
    $read = $this->user->createToken('read', ['read', 'tenant:'.$this->team->id]);
    apiCall($this, $read->plainTextToken, 'POST', $this->path.'/prompts', $data)->assertForbidden();
    $reader = User::factory()->create();
    $this->team->users()->attach($reader, ['role' => 'reader']);
    $readerToken = $reader->createToken('reader', ['write', 'tenant:'.$this->team->id]);
    apiCall($this, $readerToken->plainTextToken, 'POST', $this->path.'/prompts', $data)->assertForbidden();
    $editor = User::factory()->create();
    $this->team->users()->attach($editor, ['role' => 'editor']);
    $token = $editor->createToken('editor', ['write', 'tenant:'.$this->team->id])->plainTextToken;
    $created = apiCall($this, $token, 'POST', $this->path.'/prompts', $data)->assertCreated();
    $id = $created->json('data.id');
    apiCall($this, $token, 'PATCH', $this->path.'/prompts', ['id' => $id, 'text' => 'Changed api question?'])->assertOk()->assertJsonPath('data.text', 'Changed api question?');
    $this->context->run($this->team->id, function () use ($id) {
        expect(Prompt::findOrFail($id)->text_hash)->toBe(hash('sha256', 'Changed api question?'));
        $logs = Activity::query()->where('subject_type', Prompt::class)->where('subject_id', $id)->get();
        expect($logs)->toHaveCount(2);
        expect($logs->toJson())->not->toContain('Synthetic api test?')->not->toContain('Changed api question?');
    });
    apiCall($this, $token, 'DELETE', $this->path.'/prompts', ['id' => $id])->assertNoContent();
    $this->context->run($this->team->id, fn () => expect(Prompt::find($id))->toBeNull());
});

test('prompt validation enforces market ownership uniqueness and plan limits', function () {
    $data = ['market_id' => $this->market->id, 'text' => 'Question?', 'intent' => 'category', 'funnel_stage' => 'awareness'];
    $token = $this->token->plainTextToken;
    apiCall($this, $token, 'POST', $this->path.'/prompts', $data)->assertCreated();
    apiCall($this, $token, 'POST', $this->path.'/prompts', $data)->assertUnprocessable()->assertJsonValidationErrors('text');
    $this->team->forceFill(['plan_id' => Plan::factory()->create(['max_prompts_per_market' => 1])->id])->save();
    apiCall($this, $token, 'POST', $this->path.'/prompts', array_replace($data, ['text' => 'Another question?']))->assertUnprocessable()->assertJsonValidationErrors('market_id');
    apiCall($this, $token, 'POST', $this->path.'/prompts', ['market_id' => $this->market->id, 'text' => 'No metadata?'])->assertUnprocessable();
    apiCall($this, $token, 'POST', $this->path.'/prompts', $data + ['tenant_id' => 999])->assertUnprocessable();
});

test('active prompt capacity allows replacements but rejects reactivation beyond the limit', function () {
    $this->team->forceFill(['plan_id' => Plan::factory()->create(['max_prompts_per_market' => 1])->id])->save();
    $data = ['market_id' => $this->market->id, 'text' => 'Original question?', 'intent' => 'category', 'funnel_stage' => 'awareness'];
    $token = $this->token->plainTextToken;
    $url = $this->path.'/prompts';
    $original = apiCall($this, $token, 'POST', $url, $data)->assertCreated()->json('data.id');
    apiCall($this, $token, 'POST', $url, array_replace($data, ['text' => 'Inactive draft?', 'is_active' => false]))
        ->assertCreated()->assertJsonPath('data.is_active', false);
    apiCall($this, $token, 'POST', $url, array_replace($data, ['text' => 'Replacement?']))->assertUnprocessable();

    apiCall($this, $token, 'PATCH', $url, ['id' => $original, 'is_active' => false])->assertOk();
    $replacement = apiCall($this, $token, 'POST', $url, array_replace($data, ['text' => 'Replacement?']))
        ->assertCreated()->json('data.id');
    apiCall($this, $token, 'PATCH', $url, ['id' => $original, 'is_active' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('market_id');
    $this->context->run($this->team->id, function () use ($original) {
        expect(Prompt::findOrFail($original)->is_active)->toBeFalse();
        expect(Prompt::query()->where('market_id', $this->market->id)->where('is_active', true)->count())->toBe(1);
    });

    apiCall($this, $token, 'PATCH', $url, ['id' => $replacement, 'is_active' => false])->assertOk();
    apiCall($this, $token, 'PATCH', $url, ['id' => $original, 'is_active' => true])->assertOk();
    // A plan downgrade may leave a market above its new limit. Edits and deactivation still work.
    $this->team->plan->forceFill(['max_prompts_per_market' => 0])->save();
    apiCall($this, $token, 'PATCH', $url, ['id' => $original, 'text' => 'Edited after downgrade?', 'is_active' => true])->assertOk();
    apiCall($this, $token, 'PATCH', $url, ['id' => $original, 'is_active' => false])->assertOk();
    apiCall($this, $token, 'PATCH', $url, ['id' => $original, 'is_active' => true])->assertUnprocessable();
});

test('REST prompt writes acquire market locks before prompt locks to match MCP', function (string $method) {
    $prompt = $this->context->run($this->team->id, fn () => Prompt::factory()->create(['market_id' => $this->market->id]));
    $lockedTables = [];
    DB::listen(function ($query) use (&$lockedTables) {
        if (str_contains(strtolower($query->sql), 'for update') && preg_match('/from "(markets|prompts)"/', $query->sql, $match)) {
            $lockedTables[] = $match[1];
        }
    });

    apiCall($this, $this->token->plainTextToken, $method, $this->path.'/prompts', ['id' => $prompt->id])->assertSuccessful();

    // Reversing these locks forms a deadlock cycle with an MCP writer holding the market lock.
    expect($lockedTables)->toBe(['markets', 'prompts']);
})->with(['PATCH', 'DELETE']);

test('collections paginate and reject unbounded sizes', function () {
    $this->context->run($this->team->id, fn () => Brand::factory()->count(2)->create());
    $r = apiCall($this, $this->token->plainTextToken, 'GET', '/api/v1/brands?per_page=1&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3)->assertJsonPath('meta.current_page', 2);
    expect($r->json('links.next'))->toContain('per_page=1');
    apiCall($this, $this->token->plainTextToken, 'GET', '/api/v1/brands?per_page=101')->assertUnprocessable();
});

test('metrics and sources use MetricsService with sample sizes and pagination', function () {
    $this->context->run($this->team->id, function () {
        AggDaily::factory()->create(['brand_id' => $this->brand->id, 'market_id' => $this->market->id, 'date' => '2026-11-10', 'responses' => 4, 'brand_mentioned' => 2]);
        foreach (['a.example', 'b.example'] as $domain) {
            AggSourceDaily::factory()->create(['brand_id' => $this->brand->id, 'market_id' => $this->market->id, 'date' => '2026-11-10', 'domain' => $domain, 'responses_with_domain' => 1]);
        }
    });
    $q = '?from=2026-11-01&to=2026-11-15';
    apiCall($this, $this->token->plainTextToken, 'GET', $this->path.'/metrics'.$q.'&group_by=week')->assertOk()->assertJsonPath('data.summary.visibility.value', 0.5)->assertJsonPath('data.summary.visibility.n', 4);
    apiCall($this, $this->token->plainTextToken, 'GET', $this->path.'/sources'.$q.'&per_page=1&page=2')->assertOk()->assertJsonPath('total', 2)->assertJsonPath('data.0.n', 4)->assertJsonPath('data.0.share', 0.25);
    apiCall($this, $this->token->plainTextToken, 'GET', $this->path.'/metrics?from=2026-11-15&to=2026-11-01')->assertUnprocessable();
    apiCall($this, $this->token->plainTextToken, 'GET', $this->path.'/metrics?group_by=sql')->assertUnprocessable();
});

test('response date filters use tenant local days and omit storage and billing fields', function () {
    $this->team->forceFill(['timezone' => 'Europe/Oslo'])->save();
    $prompt = $this->context->run($this->team->id, function () {
        $prompt = Prompt::factory()->create(['market_id' => $this->market->id]);
        foreach (['2026-11-09 23:00:00', '2026-11-10 22:59:59', '2026-11-10 23:00:00'] as $date) {
            $run = Run::factory()->create(['prompt_id' => $prompt->id]);
            Response::factory()->create(['run_id' => $run->id, 'collected_at' => $date, 'raw_uri' => 'gs://private/example']);
        }

        return $prompt;
    });
    $r = apiCall($this, $this->token->plainTextToken, 'GET', $this->path.'/responses?from=2026-11-10&to=2026-11-10&prompt='.$prompt->id)->assertOk()->assertJsonCount(2, 'data');
    expect($r->getContent())->not->toContain('raw_uri')->not->toContain('cost_usd')->not->toContain('gs://');
});

test('rate limit is shared by routes but separate per token', function () {
    $this->freezeTime();
    for ($i = 0; $i < 60; $i++) {
        apiCall($this, $this->token->plainTextToken, 'GET', '/api/v1/brands')->assertOk();
    }
    apiCall($this, $this->token->plainTextToken, 'GET', '/api/v1/usage')->assertStatus(429)->assertHeader('Retry-After');
    $second = $this->user->createToken('second', ['read', 'tenant:'.$this->team->id]);
    apiCall($this, $second->plainTextToken, 'GET', '/api/v1/brands')->assertOk();
    $this->travel(61)->seconds();
    apiCall($this, $this->token->plainTextToken, 'GET', '/api/v1/brands')->assertOk();
});

test('v1 returns JSON errors without an Accept header', function () {
    $this->call('GET', '/api/v1/brands')->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
    $this->user->forceFill(['email_verified_at' => null])->save();
    Auth::forgetGuards();
    Auth::shouldUse('web');
    $this->call('GET', '/api/v1/brands', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token->plainTextToken])
        ->assertForbidden()->assertHeader('Content-Type', 'application/json')->assertHeaderMissing('Location');
    $this->user->forceFill(['email_verified_at' => now(), 'is_platform_admin' => true])->save();
    Auth::forgetGuards();
    Auth::shouldUse('web');
    $this->call('GET', '/api/v1/brands', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token->plainTextToken])
        ->assertForbidden()->assertHeader('Content-Type', 'application/json')->assertHeaderMissing('Location');
});
