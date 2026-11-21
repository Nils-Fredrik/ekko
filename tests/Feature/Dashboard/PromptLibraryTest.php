<?php

use App\Models\Market;
use App\Models\Plan;
use App\Models\Prompt;
use App\Models\Topic;
use App\Models\UsageEvent;
use App\Prompts\PromptLibrary;
use App\Tenancy\TenantContext;
use Tests\Support\Metrics\MetricsWorld;
use Tests\Support\Onboarding\OnboardingFakes;

beforeEach(function () {
    $this->world = MetricsWorld::create();
    $this->brand = $this->world->brand;
    $owner = $this->world->tenant->owner;
    $owner->switchTeam($this->world->tenant);
    $this->actingAs($owner);
    $this->bulk = route('brands.prompts.bulk', $this->brand);
});

test('topics keep stable unique keys when renamed or merged and only empty topics can be removed', function () {
    $topics = route('brands.topics.store', $this->brand);
    $a = $this->post($topics, ['name' => 'Service AS'])->assertRedirect()->getSession()->get('topic_id');
    $b = $this->post($topics, ['name' => 'Service-AS'])->assertRedirect()->getSession()->get('topic_id');
    $this->postJson($topics, ['name' => 'service as'])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->patch(route('brands.topics.update', [$this->brand, $b]), ['name' => 'Selected'])->assertSessionHasNoErrors();
    $this->postJson($this->bulk, ['ids' => [$this->world->p1->id], 'action' => 'move_topic', 'value' => $a])->assertOk();
    $this->deleteJson(route('brands.topics.destroy', [$this->brand, $a]))->assertUnprocessable()->assertJsonValidationErrors('topic_not_empty');
    $this->post(route('brands.topics.merge', [$this->brand, $a]), ['into' => $b])->assertSessionHasNoErrors();
    app(TenantContext::class)->run($this->brand->tenant_id, function () use ($a, $b) {
        expect(Topic::find($a))->toBeNull()
            ->and(Topic::findOrFail($b)->cross_market_key)->toBe('service-as-2')
            ->and($this->world->p1->fresh()->topic_id)->toBe($b);
    });
});

test('topic keys fit the wizard after transliteration and collision suffixing', function () {
    $topics = route('brands.topics.store', $this->brand);
    $name = str_repeat('Æ', 99);
    $first = $this->post($topics, ['name' => $name])->assertSessionHasNoErrors()->getSession()->get('topic_id');
    $second = $this->post($topics, ['name' => $name.'!'])->assertSessionHasNoErrors()->getSession()->get('topic_id');
    app(TenantContext::class)->run($this->brand->tenant_id, function () use ($first, $second) {
        $base = Topic::findOrFail($first)->cross_market_key;
        expect(strlen($base))->toBe(100)
            ->and(Topic::findOrFail($second)->cross_market_key)->toBe(substr($base, 0, 98).'-2');
    });
});

test('bulk moves restore mixed topics and reject an intervening change atomically', function () {
    $first = $this->world->p1;
    $second = $this->world->p2;
    $topic = Topic::factory()->for($this->brand)->create();
    $other = Topic::factory()->for($this->brand)->create();
    $first->update(['topic_id' => $topic->id]);
    $second->update(['topic_id' => null]);
    $request = ['ids' => [$first->id, $second->id], 'action' => 'move_topic', 'value' => $other->id];
    $undo = $this->postJson($this->bulk, $request)->assertOk()->assertJsonPath('affected', 2)->json('undo');
    $this->postJson($this->bulk, $undo)->assertOk();
    app(TenantContext::class)->run($this->brand->tenant_id, function () use ($first, $second, $topic) {
        expect($first->fresh()->topic_id)->toBe($topic->id)->and($second->fresh()->topic_id)->toBeNull();
    });
    $undo = $this->postJson($this->bulk, $request)->assertOk()->json('undo');
    $this->patch(route('brands.prompts.update', [$this->brand, $second]), ['topic_id' => null])->assertSessionHasNoErrors();
    $this->postJson($this->bulk, $undo)->assertUnprocessable();
    app(TenantContext::class)->run($this->brand->tenant_id, fn () => expect($first->fresh()->topic_id)->toBe($other->id));
});

test('bulk create and activation enforce capacity for each market and refuse foreign brand resources', function () {
    $this->world->tenant->forceFill(['plan_id' => Plan::factory()->create(['max_prompts_per_market' => 1])->id])->save();
    $market = Market::factory()->for($this->brand)->create();
    $items = ['text' => 'First draft?', 'intent' => 'category', 'funnel_stage' => 'awareness'];
    $batch = ['action' => 'create', 'market_id' => $market->id, 'prompts' => [$items, array_replace($items, ['text' => 'Other draft?'])]];
    $this->postJson($this->bulk, $batch)->assertUnprocessable();
    app(TenantContext::class)->run($this->brand->tenant_id, fn () => expect($market->prompts()->count())->toBe(0));
    $batch['prompts'] = [$items];
    $undo = $this->postJson($this->bulk, $batch)->assertOk()->json('undo');
    $this->postJson($this->bulk, $undo)->assertOk();
    app(TenantContext::class)->set($this->brand->tenant_id);
    $first = Prompt::factory()->for($market)->create(['is_active' => false]);
    $second = $this->world->p1;
    $second->update(['is_active' => false]);
    $this->world->p2->update(['is_active' => true]);
    $this->postJson($this->bulk, ['ids' => [$first->id, $second->id], 'action' => 'activate'])->assertUnprocessable();
    app(TenantContext::class)->set($this->brand->tenant_id);
    expect($first->fresh()->is_active)->toBeFalse()->and($second->fresh()->is_active)->toBeFalse();
    $foreign = Prompt::factory()->create();
    $this->postJson($this->bulk, ['ids' => [$first->id, $foreign->id], 'action' => 'activate'])->assertNotFound();
});

test('web deletion protects runs and archived prompts are hidden from exports and restored inactive', function () {
    $world = $this->world;
    $world->answer($world->p1, now()->toDateTimeString());
    $this->deleteJson(route('brands.prompts.destroy', [$this->brand, $world->p1]))->assertUnprocessable()->assertJsonValidationErrors('prompt_has_runs');
    $this->delete(route('brands.prompts.destroy', [$this->brand, $world->p2]))->assertRedirect();
    $this->postJson($this->bulk, ['ids' => [$world->p1->id], 'action' => 'archive'])->assertOk();
    $this->postJson($this->bulk, ['ids' => [$world->p1->id], 'action' => 'activate'])->assertUnprocessable();
    $this->get(route('brands.prompts.export', $this->brand))->assertDontSee($world->p1->text, false);
    $this->get(route('brands.prompts.export', [$this->brand, 'include_archived' => 1]))->assertSee($world->p1->text, false);
    $this->postJson($this->bulk, ['ids' => [$world->p1->id], 'action' => 'unarchive'])->assertOk();
    app(TenantContext::class)->run($world->tenant->id, fn () => expect($world->p1->fresh()->archived_at)->toBeNull()
        ->and($world->p1->fresh()->is_active)->toBeFalse()->and($world->p1->runs()->count())->toBe(1));
});

test('preview and import agree on created changed and unchanged rows without preview writes', function () {
    $world = $this->world;
    $world->market->update(['country' => 'NO']);
    $world->p1->update(['text' => 'First?', 'text_hash' => Prompt::hashText('First?'), 'priority' => 'standard']);
    $world->p2->update(['text' => 'Second?', 'text_hash' => Prompt::hashText('Second?'), 'priority' => 'core']);
    $csv = "text,market,priority\nFirst?,NO,core\nSecond?,NO,core\nThird?,NO,standard\nFourth?,XX,core\n";
    $this->postJson(route('brands.prompts.import.preview', $this->brand), ['csv' => $csv])->assertOk()
        ->assertJsonPath('unchanged', 1)->assertJsonPath('created.0.text', 'Third?')
        ->assertJsonPath('changed.0.fields.priority', ['standard', 'core'])->assertJsonPath('errors.0.line', 5);
    app(TenantContext::class)->run($world->tenant->id, fn () => expect($world->market->prompts()->count())->toBe(2)->and($world->p1->fresh()->priority)->toBe('standard'));
    $this->post(route('brands.prompts.import', $this->brand), ['csv' => $csv])->assertSessionHasNoErrors();
    app(TenantContext::class)->run($world->tenant->id, fn () => expect($world->market->prompts()->count())->toBe(3)->and($world->p1->fresh()->priority)->toBe('core'));
});

test('topic suggestions bill once, omit existing text, and refuse calls beyond quota', function () {
    $topic = Topic::factory()->for($this->brand)->create(['cross_market_key' => 'k']);
    $fake = OnboardingFakes::bind(OnboardingFakes::marketJson(['k', 'k']));
    $world = $this->world;
    $existing = 'Vilken löparsko är bäst för nybörjare 0?';
    $world->p1->update(['text' => $existing, 'text_hash' => Prompt::hashText($existing)]);
    $world->tenant->forceFill(['monthly_call_quota_override' => 1])->save();
    $url = route('brands.topics.suggestions', [$this->brand, $topic]);
    $data = ['market_id' => $world->market->id, 'count' => 5];
    $this->postJson($url, $data)->assertOk()->assertJsonCount(1, 'suggestions')->assertJsonPath('suggestions.0.intent', 'problem');
    $this->postJson($url, $data)->assertTooManyRequests();
    expect($fake->model->requests)->toHaveCount(1);
    app(TenantContext::class)->run($world->tenant->id, fn () => expect(UsageEvent::query()->where('kind', 'onboarding')->sum('calls'))->toEqual(1)
        ->and($world->market->prompts()->count())->toBe(2));
});

test('setup payload includes prompt results, topic counts and last measurement for the selected market', function () {
    $world = $this->world;
    $topic = Topic::factory()->for($this->brand)->create();
    $world->p1->update(['topic_id' => $topic->id, 'is_active' => true]);
    $world->answer($world->p1, now()->subDay()->toDateTimeString(), ['brand' => 1]);
    $payload = app(PromptLibrary::class)->payload($this->brand, $world->market, ['topic_id' => $topic->id]);
    expect($payload['prompts'])->toHaveCount(1)->and($payload['prompts'][0]['runs_exists'])->toBeTrue()->and($payload['prompts'][0]['last_measured_at'])->not->toBeNull();
    expect(collect($payload['topics'])->firstWhere('id', $topic->id)['count'])->toBe(1);
});

test('topic management and bulk reject another brand in the same tenant', function () {
    $foreign = Market::factory()->create();
    $topic = Topic::factory()->create(['brand_id' => $foreign->brand_id]);
    $own = Topic::factory()->for($this->brand)->create();
    $suggest = route('brands.topics.suggestions', [$this->brand, $own]);
    $fake = OnboardingFakes::bind();
    $this->postJson($suggest, ['market_id' => $foreign->id, 'count' => 5])->assertNotFound();
    $this->postJson(route('brands.topics.merge', [$this->brand, $own]), ['into' => $topic->id])->assertNotFound();
    $this->patchJson(route('brands.topics.update', [$this->brand, $topic]), ['name' => 'New'])->assertNotFound();
    $this->postJson($this->bulk, ['ids' => [$this->world->p1->id], 'action' => 'move_topic', 'value' => $topic->id])->assertNotFound();
    expect($fake->model->requests)->toBeEmpty();
});

test('suggestion limit is shared across topics for one user', function () {
    $topics = Topic::factory()->count(2)->for($this->brand)->create();
    $fake = OnboardingFakes::bind();
    $urls = $topics->map(fn (Topic $topic) => route('brands.topics.suggestions', [$this->brand, $topic]));
    for ($i = 0; $i < 10; $i++) {
        $this->postJson($urls[$i % 2], ['market_id' => $this->world->market->id, 'count' => 4])->assertUnprocessable();
    }
    $this->postJson($urls[0], ['market_id' => $this->world->market->id, 'count' => 5])->assertTooManyRequests();
    expect($fake->model->requests)->toBeEmpty();
});

test('undoing an archive restores the original activation state of each prompt', function () {
    $first = $this->world->p1;
    $second = $this->world->p2;
    $first->update(['is_active' => true]);
    $second->update(['is_active' => false]);
    $undo = $this->postJson($this->bulk, ['ids' => [$first->id, $second->id], 'action' => 'archive'])
        ->assertOk()->assertJsonPath('affected', 2)->json('undo');
    $this->postJson($this->bulk, $undo)->assertOk();
    app(TenantContext::class)->run($this->brand->tenant_id, function () use ($first, $second) {
        expect($first->fresh()->is_active)->toBeTrue()->and($second->fresh()->is_active)->toBeFalse()
            ->and($first->fresh()->archived_at)->toBeNull()->and($second->fresh()->archived_at)->toBeNull();
    });
});
