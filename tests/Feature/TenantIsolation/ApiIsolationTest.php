<?php

use App\Http\Middleware\ApiTokenContext;
use App\Models\Alert;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\Market;
use App\Models\Prompt;
use App\Models\Response;
use App\Models\Run;
use App\Models\Team;
use App\Models\Topic;
use App\Models\UsageMonthly;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

function isolatedApiCall($test, string $token, string $method, string $url, array $data = [])
{
    Auth::forgetGuards();
    Auth::shouldUse('web');

    return $test->json($method, $url, $data, ['Authorization' => 'Bearer '.$token]);
}

beforeEach(function () {
    $this->a = User::factory()->withPersonalTeam()->create();
    $this->teamA = $this->a->ownedTeams()->firstOrFail();
    $this->a->switchTeam($this->teamA);
    $this->teamB = Team::factory()->create();
    $this->context = app(TenantContext::class);
    $this->fixtures = [];
    foreach ([$this->teamA, $this->teamB] as $team) {
        $this->fixtures[$team->id] = $this->context->run($team->id, function () use ($team) {
            $brand = Brand::factory()->create(['name' => 'TenantMarker'.$team->id]);
            $market = Market::factory()->create(['brand_id' => $brand->id]);
            $topic = Topic::factory()->create(['brand_id' => $brand->id]);
            $prompt = Prompt::factory()->create(['market_id' => $market->id, 'topic_id' => $topic->id, 'text' => 'PromptMarker'.$team->id]);
            $run = Run::factory()->create(['prompt_id' => $prompt->id]);
            $response = Response::factory()->create(['run_id' => $run->id, 'answer_text' => 'AnswerMarker'.$team->id]);
            Competitor::factory()->create(['brand_id' => $brand->id, 'name' => 'CompetitorMarker'.$team->id]);
            Alert::factory()->create(['payload' => ['message' => 'AlertMarker'.$team->id]]);
            UsageMonthly::factory()->create(['month' => today()->startOfMonth(), 'calls' => $team->id]);

            return compact('brand', 'market', 'topic', 'prompt', 'response');
        });
    }
    $this->token = $this->a->createToken('isolation', ['read', 'write', 'tenant:'.$this->teamA->id]);
});

test('every v1 collection contains only the token tenant data', function () {
    $brand = $this->fixtures[$this->teamA->id]['brand'];
    foreach (['/brands', '/alerts', '/usage', ...array_map(fn ($part) => '/brands/'.$brand->id.'/'.$part, ['metrics', 'prompts', 'responses', 'sources', 'competitors'])] as $path) {
        $response = isolatedApiCall($this, $this->token->plainTextToken, 'GET', '/api/v1'.$path)->assertOk();
        foreach (['TenantMarker', 'PromptMarker', 'AnswerMarker', 'CompetitorMarker', 'AlertMarker'] as $marker) {
            expect($response->getContent())->not->toContain($marker.$this->teamB->id);
        }
    }
    isolatedApiCall($this, $this->token->plainTextToken, 'GET', '/api/v1/usage')->assertJsonPath('data.calls', $this->teamA->id);
});

test('foreign market topic and prompt ids cannot be read changed or deleted through an owned brand', function () {
    $own = $this->fixtures[$this->teamA->id];
    $foreign = $this->fixtures[$this->teamB->id];
    $path = '/api/v1/brands/'.$own['brand']->id;
    $before = $this->context->run($this->teamB->id, fn () => $foreign['prompt']->fresh()->toArray());
    $data = ['market_id' => $own['market']->id, 'text' => 'A synthetic API question?', 'intent' => 'category', 'funnel_stage' => 'awareness'];
    isolatedApiCall($this, $this->token->plainTextToken, 'POST', $path.'/prompts', array_replace($data, ['market_id' => $foreign['market']->id]))->assertNotFound();
    isolatedApiCall($this, $this->token->plainTextToken, 'POST', $path.'/prompts', $data + ['topic_id' => $foreign['topic']->id])->assertNotFound();
    foreach (['PATCH', 'DELETE'] as $method) {
        isolatedApiCall($this, $this->token->plainTextToken, $method, $path.'/prompts', ['id' => $foreign['prompt']->id, 'text' => 'Must not persist?'])->assertNotFound();
    }
    isolatedApiCall($this, $this->token->plainTextToken, 'GET', $path.'/responses?prompt='.$foreign['prompt']->id)->assertNotFound();
    isolatedApiCall($this, $this->token->plainTextToken, 'GET', $path.'/metrics?market='.$foreign['market']->id)->assertNotFound();
    expect($this->context->run($this->teamB->id, fn () => $foreign['prompt']->fresh()->toArray()))->toBe($before);
});

test('same tenant resources are still confined to the selected brand', function () {
    $other = $this->context->run($this->teamA->id, fn () => Prompt::factory()->create());
    $path = '/api/v1/brands/'.$this->fixtures[$this->teamA->id]['brand']->id;
    isolatedApiCall($this, $this->token->plainTextToken, 'PATCH', $path.'/prompts', ['id' => $other->id, 'text' => 'Wrong brand?'])->assertNotFound();
    isolatedApiCall($this, $this->token->plainTextToken, 'GET', $path.'/responses?prompt='.$other->id)->assertNotFound();
});

test('partner tokens remain bound to one tenant even in raw SQL without the model scope', function () {
    $partner = User::factory()->withTwoFactor()->create();
    $this->teamA->users()->attach($partner, ['role' => 'partner_advisor']);
    $client = Team::factory()->clientOf($this->teamA)->create();
    $clientBrand = $this->context->run($client->id, fn () => Brand::factory()->create());
    $token = $partner->createToken('partner', ['read', 'tenant:'.$this->teamA->id]);
    isolatedApiCall($this, $token->plainTextToken, 'GET', '/api/v1/brands/'.$clientBrand->id.'/prompts')->assertNotFound();
    $request = Request::create('/api/v1/brands');
    $request->setUserResolver(fn () => $partner->withAccessToken($token->accessToken));
    $result = app(ApiTokenContext::class)->handle($request, fn () => response()->json([
        'tenants' => DB::table('brands')->distinct()->pluck('tenant_id')->all(),
        'context' => current_tenant_id(),
    ]));
    expect($result->getData(true))->toBe(['tenants' => [$this->teamA->id], 'context' => $this->teamA->id]);
    expect($this->context->id())->toBeNull();
    $clientToken = $partner->createToken('client', ['read', 'tenant:'.$client->id]);
    isolatedApiCall($this, $clientToken->plainTextToken, 'GET', '/api/v1/brands')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $clientBrand->id);
    $partner->forceFill(['two_factor_confirmed_at' => null])->save();
    isolatedApiCall($this, $clientToken->plainTextToken, 'GET', '/api/v1/brands')->assertForbidden();
});

test('revoking a token denies the next API call', function () {
    $this->token->accessToken->delete();
    isolatedApiCall($this, $this->token->plainTextToken, 'GET', '/api/v1/brands')->assertUnauthorized();
});
