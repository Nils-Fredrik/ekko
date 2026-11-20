<?php

use App\Models\Action;
use App\Models\Brand;
use App\Models\BrandFact;
use App\Models\Citation;
use App\Models\Claim;
use App\Models\Report;
use App\Models\Response;
use App\Models\Team;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Feature\TenantIsolation\Support\Scenario;
use Tests\Support\Mcp\McpTestClient;

function productCall($test, string $token, string $method, string $path, array $data = [])
{
    Auth::forgetGuards();
    Auth::shouldUse('web');

    return $test->json($method, '/api/v1'.$path, $data, ['Authorization' => 'Bearer '.$token]);
}

beforeEach(function () {
    Bus::fake();
    $this->s = Scenario::create();
    $this->a = app(TenantContext::class)->run($this->s->tenantA->id, function () {
        $brand = Brand::factory()->create();
        $fact = BrandFact::factory()->create(['brand_id' => $brand->id]);
        $response = Response::factory()->create();
        $citation = Citation::factory()->create(['response_id' => $response->id]);
        $claim = Claim::query()->create(['brand_id' => $brand->id, 'response_id' => $response->id, 'fact_id' => $fact->id,
            'text' => 'Untrusted assertion', 'quote' => 'Untrusted quote', 'verdict' => 'contradicted', 'source_citation_ids' => [$citation->id]]);

        return ['brand' => $brand, 'fact' => $fact, 'claim' => $claim, 'citation' => $citation,
            'action' => Action::factory()->create(['brand_id' => $brand->id, 'status' => 'planned']),
            'report' => Report::factory()->create(['pdf_uri' => 'reports/private.pdf', 'sent_to' => ['private@example.test'],
                'web_snapshot' => ['brand' => 'Synthetic report', 'insight' => 'Untrusted insight', 'secret_field' => 'never expose']])];
    });
    $this->b = app(TenantContext::class)->run($this->s->tenantB->id, function () {
        $brand = Brand::factory()->create();

        return ['brand' => $brand, 'fact' => BrandFact::factory()->create(['brand_id' => $brand->id]),
            'action' => Action::factory()->create(['brand_id' => $brand->id, 'status' => 'planned']), 'report' => Report::factory()->create()];
    });
    $this->token = $this->s->adminA->createToken('product', ['read', 'write', 'tenant:'.$this->s->tenantA->id])->plainTextToken;
    $this->brandPath = '/brands/'.$this->a['brand']->id;
});

test('actions are filtered and paginated and use the shared transition rules', function () {
    $action = productCall($this, $this->token, 'POST', $this->brandPath.'/actions', ['title' => 'My action', 'description' => '', 'steps' => ['Check source']])
        ->assertCreated()->assertJsonPath('data.status', 'planned')->json('data.id');
    productCall($this, $this->token, 'GET', $this->brandPath.'/actions?status=planned&per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('total', 2);
    productCall($this, $this->token, 'PATCH', $this->brandPath.'/actions', ['id' => $action, 'status' => 'in_progress'])->assertOk()->assertJsonPath('data.status', 'in_progress');
    $started = app(TenantContext::class)->run($this->s->tenantA->id, fn () => Action::findOrFail($action)->started_at);
    $this->travel(1)->day();
    productCall($this, $this->token, 'PATCH', $this->brandPath.'/actions', ['id' => $action, 'status' => 'in_progress'])->assertOk();
    expect(app(TenantContext::class)->run($this->s->tenantA->id, fn () => Action::findOrFail($action)->started_at)->equalTo($started))->toBeTrue();
    $this->travelBack();
    productCall($this, $this->token, 'PATCH', $this->brandPath.'/actions', ['id' => $action, 'status' => 'expired'])->assertUnprocessable();
    productCall($this, $this->token, 'GET', '/actions/'.$action)->assertOk()->assertJsonPath('data.title', 'My action');
    productCall($this, $this->token, 'GET', $this->brandPath.'/actions?type=invalid')->assertUnprocessable();
    productCall($this, $this->token, 'GET', $this->brandPath.'/actions?per_page=101')->assertUnprocessable();
});

test('facts invalidate old claims and return source evidence under the untrusted field', function () {
    productCall($this, $this->token, 'GET', $this->brandPath.'/claims?verdict=contradicted')->assertOk()
        ->assertJsonPath('data.0.untrusted_engine_output.text', 'Untrusted assertion')
        ->assertJsonPath('data.0.untrusted_engine_output.sources.0.id', $this->a['citation']->id);
    productCall($this, $this->token, 'PATCH', $this->brandPath.'/facts', ['id' => $this->a['fact']->id, 'text' => 'Revised fact'])->assertOk();
    productCall($this, $this->token, 'GET', $this->brandPath.'/claims')->assertOk()->assertJsonCount(0, 'data');
    $id = productCall($this, $this->token, 'POST', $this->brandPath.'/facts', ['text' => 'Another fact', 'is_active' => false])->assertCreated()->json('data.id');
    productCall($this, $this->token, 'DELETE', $this->brandPath.'/facts', ['id' => $id])->assertNoContent();
    productCall($this, $this->token, 'GET', $this->brandPath.'/facts')->assertOk()->assertJsonCount(1, 'data');
});

test('foreign body IDs and a different brand in the same tenant cannot be modified', function () {
    foreach ([$this->b['brand'], app(TenantContext::class)->run($this->s->tenantA->id, fn () => Brand::factory()->create())] as $brand) {
        productCall($this, $this->token, 'PATCH', '/brands/'.$brand->id.'/actions', ['id' => $this->a['action']->id, 'status' => 'done'])->assertNotFound();
        productCall($this, $this->token, 'PATCH', '/brands/'.$brand->id.'/facts', ['id' => $this->a['fact']->id, 'is_active' => false])->assertNotFound();
    }
    productCall($this, $this->token, 'PATCH', $this->brandPath.'/actions', ['id' => $this->b['action']->id, 'status' => 'done'])->assertNotFound();
    productCall($this, $this->token, 'DELETE', $this->brandPath.'/facts', ['id' => $this->b['fact']->id])->assertNotFound();
    app(TenantContext::class)->run($this->s->tenantB->id, function () {
        expect($this->b['action']->fresh()->status->value)->toBe('planned')->and($this->b['fact']->fresh())->not->toBeNull();
    });
});

test('report metadata hides private fields and sharing is audited without its token', function () {
    $path = '/reports/'.$this->a['report']->id;
    $read = productCall($this, $this->token, 'GET', $path)->assertOk()->assertJsonPath('data.content.brand', 'Synthetic report');
    expect($read->getContent())->not->toContain('private@example.test', 'private.pdf', 'never expose');
    productCall($this, $this->token, 'GET', '/reports')->assertOk()->assertJsonCount(1, 'data');
    productCall($this, $this->token, 'GET', '/reports/'.$this->b['report']->id)->assertNotFound();
    $link = productCall($this, $this->token, 'POST', $path.'/links', ['days' => 2])->assertCreated()->json('data');
    $secret = explode('#', $link['url'])[1];
    $logs = DB::table('activity_log')->where('event', 'reports.link_created')->get()->toJson();
    expect($logs)->toContain('report_id')->not->toContain($secret, hash('sha256', $secret));
    productCall($this, $this->token, 'DELETE', '/reports/'.$this->b['report']->id.'/links', ['id' => $link['id']])->assertNotFound();
    $other = app(TenantContext::class)->run($this->s->tenantA->id, fn () => Report::factory()->create());
    productCall($this, $this->token, 'DELETE', '/reports/'.$other->id.'/links', ['id' => $link['id']])->assertNotFound();
    expect(DB::table('report_links')->where('id', $link['id'])->value('revoked_at'))->toBeNull();
    productCall($this, $this->token, 'DELETE', $path.'/links', ['id' => $link['id']])->assertNoContent();
    $this->get(explode('#', $link['url'])[0])->assertNotFound();
});

test('write ability never replaces the current role and read-only tokens cannot mutate', function () {
    $reader = $this->s->userWithRole('reader');
    $token = $reader->createToken('reader', ['read', 'write', 'tenant:'.$this->s->tenantA->id])->plainTextToken;
    productCall($this, $token, 'POST', $this->brandPath.'/actions', ['title' => 'Denied'])->assertForbidden();
    productCall($this, $token, 'POST', '/reports/'.$this->a['report']->id.'/links')->assertForbidden();
    $readOnly = $this->s->adminA->createToken('read only', ['read', 'tenant:'.$this->s->tenantA->id])->plainTextToken;
    productCall($this, $readOnly, 'POST', $this->brandPath.'/facts', ['text' => 'Denied'])->assertForbidden();
    $editor = $this->s->userWithRole('editor')->createToken('editor', ['read', 'write', 'tenant:'.$this->s->tenantA->id])->plainTextToken;
    productCall($this, $editor, 'POST', $this->brandPath.'/facts', ['text' => 'Denied'])->assertForbidden();
});

test('pitch provisioning is partner-bound, quota-limited and does not broaden the REST token', function () {
    $advisor = $this->s->userWithRole('partner_advisor');
    $token = $advisor->createToken('partner', ['read', 'write', 'tenant:'.$this->s->tenantA->id])->plainTextToken;
    $input = ['name' => 'Synthetic pitch', 'domain' => 'https://example.test/', 'country' => 'NO', 'language' => 'nb'];
    productCall($this, $this->token, 'POST', '/pitches', $input)->assertForbidden();
    productCall($this, $token, 'POST', '/pitches', $input + ['partner_team_id' => $this->s->tenantB->id])->assertUnprocessable();
    $pitch = productCall($this, $token, 'POST', '/pitches', $input)->assertCreated()->assertJsonPath('data.partner_team_id', $this->s->tenantA->id)->json('data.id');
    $brand = app(TenantContext::class)->run($pitch, fn () => Brand::query()->sole());
    expect($brand->primary_domain)->toBe('example.test');
    productCall($this, $token, 'GET', '/brands/'.$brand->id.'/facts')->assertNotFound();
    Team::factory()->clientOf($this->s->tenantB)->create(['kind' => 'pitch']);
    productCall($this, $token, 'POST', '/pitches', array_replace($input, ['domain' => '127.0.0.1']))->assertUnprocessable();
    productCall($this, $token, 'GET', '/pitches')->assertOk()->assertJsonCount(1, 'data');
    productCall($this, $token, 'POST', '/pitches', $input)->assertCreated();
    productCall($this, $token, 'POST', '/pitches', $input)->assertCreated();
    productCall($this, $token, 'POST', '/pitches', $input)->assertUnprocessable();
    Bus::assertNothingDispatched();
});

// These calls use the real OAuth token and HTTP MCP server, not direct tool invocation.
test('MCP writes require consent, are audited and cannot alter another tenants products', function () {
    $read = McpTestClient::connect($this->s->adminA, $this->s->tenantA);
    $tools = McpTestClient::tools($this, $read);
    expect($tools)->not->toContain('update_action', 'create_report_link', 'create_pitch');
    foreach ([
        'update_action' => ['brand' => $this->a['brand']->id, 'title' => 'Denied'],
        'create_report_link' => ['report' => $this->a['report']->id],
        'create_pitch' => ['name' => 'Denied', 'domain' => 'example.test', 'country' => 'NO', 'language' => 'nb'],
    ] as $tool => $arguments) {
        McpTestClient::rpc($this, $read, 'tools/call', ['name' => $tool, 'arguments' => $arguments])
            ->assertBadRequest()->assertJsonPath('error.code', -32602)
            ->assertJsonPath('error.message', "Tool [{$tool}] not found.");
    }
    $write = McpTestClient::connect($this->s->adminA, $this->s->tenantA, write: true);
    $result = McpTestClient::call($this, $write, 'update_action', ['brand' => $this->a['brand']->id, 'id' => $this->b['action']->id, 'status' => 'done']);
    expect($result['isError'])->toBeTrue();
    $result = McpTestClient::call($this, $write, 'update_action', ['brand' => $this->a['brand']->id, 'id' => $this->a['action']->id, 'status' => 'in_progress']);
    expect($result['isError'])->toBeFalse()->and($result['data']['status'])->toBe('in_progress');
    $result = McpTestClient::call($this, $write, 'create_report_link', ['report' => $this->b['report']->id]);
    expect($result['isError'])->toBeTrue();
    $result = McpTestClient::call($this, $write, 'create_report_link', ['report' => $this->a['report']->id]);
    expect($result['isError'])->toBeFalse();
    $secret = explode('#', $result['data']['link']['url'])[1];
    $logs = DB::table('activity_log')->where('event', 'like', 'mcp.%')->get()->toJson();
    expect($logs)->toContain('mcp.update_action', 'mcp.create_report_link')->not->toContain($secret, hash('sha256', $secret));
});

test('MCP reads page actions and mark model text as untrusted', function () {
    config(['ekko.mcp.page_size' => 1]);
    app(TenantContext::class)->run($this->s->tenantA->id, fn () => Action::factory()->create(['brand_id' => $this->a['brand']->id]));
    $token = McpTestClient::connect($this->s->adminA, $this->s->tenantA);
    $first = McpTestClient::call($this, $token, 'list_actions', ['brand' => $this->a['brand']->id]);
    expect($first['isError'])->toBeFalse()->and($first['data']['actions'])->toHaveCount(1)->and($first['data']['next_cursor'])->not->toBeNull();
    $second = McpTestClient::call($this, $token, 'list_actions', ['brand' => $this->a['brand']->id, 'cursor' => $first['data']['next_cursor']]);
    expect($second['data']['actions'][0]['id'])->not->toBe($first['data']['actions'][0]['id']);
    $claims = McpTestClient::call($this, $token, 'get_fact_check', ['brand' => $this->a['brand']->id]);
    expect($claims['isError'])->toBeFalse()->and($claims['data']['claims'][0])->not->toHaveKey('text')
        ->and($claims['data']['claims'][0]['untrusted_engine_output']['text'])->toBe('Untrusted assertion');
    $report = McpTestClient::call($this, $token, 'get_report', ['report' => $this->a['report']->id]);
    expect($report['isError'])->toBeFalse()->and($report['data']['report']['untrusted_engine_output']['content']['insight'])->toBe('Untrusted insight');
});

test('MCP pitch creation is audited in the partner and restores its tenant context', function () {
    $advisor = $this->s->userWithRole('partner_advisor');
    $token = McpTestClient::connect($advisor, $this->s->tenantA, write: true);
    $input = ['name' => 'Synthetic MCP pitch', 'domain' => 'example.test', 'country' => 'NO', 'language' => 'nb'];
    $denied = McpTestClient::call($this, $token, 'create_pitch', $input + ['tenant' => $this->s->tenantB->id]);
    expect($denied['isError'])->toBeTrue();
    $created = McpTestClient::call($this, $token, 'create_pitch', $input);
    expect($created['isError'])->toBeFalse()->and($created['data']['pitch']['partner_team_id'])->toBe($this->s->tenantA->id);
    $id = $created['data']['pitch']['id'];
    $log = DB::table('activity_log')->where('event', 'mcp.create_pitch')->sole();
    expect($log->tenant_id)->toBe($this->s->tenantA->id)->and(json_decode($log->properties, true))->toMatchArray(['pitch_id' => $id]);
    $own = McpTestClient::call($this, $token, 'list_brands');
    expect($own['data']['tenant']['id'])->toBe($this->s->tenantA->id);
    $pitch = McpTestClient::call($this, $token, 'list_brands', ['tenant' => $id]);
    expect($pitch['isError'])->toBeFalse()->and($pitch['data']['tenant']['id'])->toBe($id);
    Bus::assertNothingDispatched();
});

test('platform admin still needs a bound tenant token to create a pitch', function () {
    $admin = $this->s->platformAdmin;
    $token = $admin->createToken('bound admin', ['read', 'write', 'tenant:'.$this->s->tenantA->id])->plainTextToken;
    $input = ['name' => 'Admin pitch', 'domain' => 'example.test', 'country' => 'NO', 'language' => 'nb'];
    productCall($this, $token, 'POST', '/pitches', $input)->assertForbidden();
    $this->s->tenantA->users()->attach($admin, ['role' => 'admin']);
    productCall($this, $token, 'POST', '/pitches', ['name' => 'Admin pitch', 'domain' => 'example.test', 'country' => 'NO', 'language' => 'nb'])
        ->assertCreated()->assertJsonPath('data.partner_team_id', $this->s->tenantA->id);
});
