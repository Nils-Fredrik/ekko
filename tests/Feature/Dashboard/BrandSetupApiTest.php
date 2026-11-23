<?php

use App\Models\Brand;
use App\Models\BrandPlanEngine;
use App\Models\Competitor;
use App\Models\EnginePrice;
use App\Models\Market;
use App\Models\Mention;
use App\Models\Plan;
use App\Models\Prompt;
use App\Models\Topic;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\Metrics\MetricsWorld;

beforeEach(function () {
    $this->world = MetricsWorld::create();
    $this->brand = $this->world->brand;
    $owner = $this->world->tenant->owner;
    $owner->switchTeam($this->world->tenant);
    $this->actingAs($owner);
});

it('creates an inheriting market and copies active prompts of the same language up to the plan limit', function () {
    $w = $this->world;
    $w->tenant->forceFill(['plan_id' => Plan::factory()->create(['max_markets_per_brand' => 2, 'max_prompts_per_market' => 1, 'allowed_engines' => [$w->engine->key]])->id])->save();
    $w->engine->update(['is_enabled' => true]);
    EnginePrice::factory()->for($w->engine)->create(['valid_from' => now()->subDay()]);
    BrandPlanEngine::factory()->for($w->brand)->create(['engine_key' => $w->engine->key, 'frequency' => 'weekly', 'prompt_scope' => 'all', 'repetitions' => 1]);
    $w->market->update(['country' => 'NO', 'language' => 'nb']);
    $topic = Topic::factory()->for($w->brand)->create();
    $w->p1->update(['priority' => 'standard', 'is_active' => true]);
    $w->p2->update(['priority' => 'core', 'intent' => 'brand', 'funnel_stage' => 'decision', 'topic_id' => $topic->id, 'is_active' => true]);
    Prompt::factory()->for($w->market)->create(['is_active' => false, 'priority' => 'core']);
    $path = route('brands.markets.store', $w->brand);
    $data = ['country' => 'se', 'language' => 'NB', 'copy_from' => $w->market->id];
    $this->postJson($path, array_replace($data, ['language' => 'sv']))->assertUnprocessable()->assertJsonValidationErrors('copy_from');
    $id = $this->post($path, $data)->assertRedirect(route('brands.setup.markets', $w->brand))->assertSessionHasNoErrors()->getSession()->get('market_id');
    $this->get(route('brands.setup.markets', $w->brand))->assertOk()->assertInertia(fn ($page) => $page->where('selectedMarketId', $id));
    app(TenantContext::class)->run($w->tenant->id, function () use ($id, $w, $topic) {
        $market = Market::findOrFail($id);
        $copy = $market->prompts()->sole();
        expect([$market->country, $market->language, $market->plan_mode])->toBe(['SE', 'nb', 'inherit'])
            ->and($market->marketEngines()->sole()->frequency)->toBe('weekly')
            ->and([$copy->text, $copy->topic_id, $copy->intent, $copy->funnel_stage, $copy->priority, $copy->is_active])
            ->toBe([$w->p2->text, $topic->id, 'brand', 'decision', 'core', true]);
    });
    $this->postJson($path, ['country' => 'DK', 'language' => 'da'])->assertUnprocessable()->assertJsonValidationErrors('country');
    $this->patch(route('brands.markets.update', [$w->brand, $id]), ['location_label' => 'Nord', 'is_active' => false])->assertSessionHasNoErrors();
    app(TenantContext::class)->run($w->tenant->id, fn () => expect(Market::findOrFail($id)->only('location_label', 'is_active'))->toBe(['location_label' => 'Nord', 'is_active' => false]));
});

it('leaves archived and inactive prompts behind and rejects an existing country and language', function () {
    $w = $this->world;
    $w->market->update(['country' => 'NO', 'language' => 'en-GB']);
    $w->p1->update(['is_active' => false, 'archived_at' => now()]);
    $w->p2->update(['is_active' => false]);
    $path = route('brands.markets.store', $w->brand);
    $data = ['country' => 'NO', 'language' => 'en-gb', 'copy_from' => $w->market->id];
    $this->postJson($path, $data)->assertUnprocessable()->assertJsonValidationErrors('country');
    $id = $this->post($path, array_replace($data, ['country' => 'SE']))->assertSessionHasNoErrors()->getSession()->get('market_id');
    app(TenantContext::class)->run($w->tenant->id, fn () => expect(Market::findOrFail($id)->prompts()->count())->toBe(0));
});

it('requires the current name to archive a brand and removes it from selectors while retaining history', function (string $status) {
    $brand = $this->brand;
    $world = $this->world;
    app(TenantContext::class)->run($world->tenant->id, fn () => $brand->update(['status' => $status]));
    $answer = $world->answer($world->p1, now()->subDay()->toDateTimeString());
    $archive = route('brands.archive', $brand);
    foreach (['', $brand->name.' ', 'Another brand'] as $wrong) {
        $this->postJson($archive, ['brand_name_confirmation' => $wrong])->assertUnprocessable()->assertJsonValidationErrors('brand_name_confirmation');
    }
    $this->patchJson(route('brands.identity.update', $brand), ['status' => 'archived'])->assertUnprocessable();
    $this->getJson(route('palette.search', ['q' => $brand->name]))->assertJsonPath('brands.0.id', $brand->id);
    $this->post($archive, ['brand_name_confirmation' => $brand->name])->assertRedirect(route('brands.index'))->assertSessionHasNoErrors();
    $this->get(route('brands.index'))->assertInertia(fn ($page) => $page->has('brands', 0)
        ->loadDeferredProps('workspace', fn ($deferred) => $deferred->has('workspace.brands', 0)));
    $this->getJson(route('palette.search', ['q' => $brand->name]))->assertJsonCount(0, 'brands');
    $this->patchJson(route('brands.identity.update', $brand), ['status' => 'active'])->assertNotFound();
    app(TenantContext::class)->run($world->tenant->id, function () use ($brand, $answer) {
        expect($brand->fresh()->status)->toBe('archived')->and($answer->fresh())->not->toBeNull();
    });
})->with(['active', 'paused']);

it('updates identity, domains and aliases without losing other settings or deleting the last alias', function () {
    $w = $this->world;
    $alias = $this->brand->aliases()->create(['alias' => 'Example', 'match_mode' => 'exact_word']);
    $other = Brand::factory()->create();
    $foreign = $other->aliases()->create(['alias' => 'Other', 'match_mode' => 'exact_word']);
    $domain = $this->brand->domains()->create(['domain' => 'one.example', 'include_subdomains' => true]);
    $this->patch(route('brands.identity.update', $this->brand), ['primary_domain' => 'BRAND.EXAMPLE', 'status' => 'paused'])->assertSessionHasNoErrors();
    $this->deleteJson(route('brands.aliases.destroy', [$this->brand, $alias]))->assertUnprocessable()->assertJsonValidationErrors('alias');
    $this->patchJson(route('brands.aliases.update', [$this->brand, $foreign]), ['match_mode' => 'regex'])->assertNotFound();
    $this->post(route('brands.aliases.store', $this->brand), ['alias' => 'Sy[dn]', 'match_mode' => 'regex'])->assertSessionHasNoErrors();
    $this->patch(route('brands.aliases.update', [$this->brand, $alias]), ['match_mode' => 'case_insensitive'])->assertSessionHasNoErrors();
    $this->delete(route('brands.aliases.destroy', [$this->brand, $alias]))->assertSessionHasNoErrors();
    $this->post(route('brands.domains.store', $this->brand), ['domain' => 'https://WWW.EXAMPLE.COM/a', 'include_subdomains' => true])->assertSessionHasNoErrors();
    $this->patch(route('brands.domains.update', [$this->brand, $domain]), ['include_subdomains' => false])->assertSessionHasNoErrors();
    app(TenantContext::class)->run($w->tenant->id, function () use ($domain) {
        expect($this->brand->fresh()->primary_domain)->toBe('brand.example')->and($this->brand->fresh()->status)->toBe('paused')
            ->and($this->brand->aliases()->sole()->alias)->toBe('Sy[dn]')->and($domain->fresh()->include_subdomains)->toBeFalse();
    });
    $this->delete(route('brands.domains.destroy', [$this->brand, $domain]))->assertSessionHasNoErrors();
    app(TenantContext::class)->run($w->tenant->id, fn () => expect($this->brand->domains()->sole()->domain)->toBe('example.com'));
});

it('limits integration writes to the selected provider and preserves stored credentials on a partial update', function () {
    $url = route('brands.integrations.update', [$this->brand, 'ga4']);
    $this->putJson($url, ['clickhouse_table' => 'synthetic'])->assertUnprocessable()->assertJsonValidationErrors('clickhouse_table');
    $this->put($url, ['ga4_property_id' => '125', 'ga4_client_email' => 'test@example.test', 'ga4_private_key' => 'fixture-value'])->assertSessionHasNoErrors();
    $this->put($url, ['ga4_property_id' => '126', 'ga4_private_key' => ''])->assertSessionHasNoErrors();
    app(TenantContext::class)->run($this->world->tenant->id, function () {
        $config = $this->brand->integrations()->sole()->config;
        expect($config['property_id'])->toBe('126')->and($config['service_account']['private_key'])->toBe('fixture-value');
    });
});

it('previews detection on recent answers of the brand and counts only overlapping classifications', function () {
    $w = $this->world;
    $w->answer($w->p1, now()->subDays(31)->toDateTimeString())->update(['answer_text' => 'Navn']);
    $other = Market::factory()->create();
    $w->answer(Prompt::factory()->for($other)->create(), now()->subHour()->toDateTimeString())->update(['answer_text' => 'Navn']);
    $answer = $w->answer($w->p1, now()->subHour()->toDateTimeString());
    $answer->update(['answer_text' => 'ÆØ Navn og Rival.']);
    Mention::factory()->create(['response_id' => $answer->id, 'entity_type' => 'competitor', 'entity_id' => $w->c1->id, 'surface_name' => 'Navn', 'first_char_offset' => 3]);
    Mention::factory()->create(['response_id' => $answer->id, 'entity_type' => 'competitor', 'entity_id' => $w->c2->id, 'surface_name' => 'Rival', 'first_char_offset' => 11]);
    $w->answer($w->p2, now()->subDays(2)->toDateTimeString())->update(['answer_text' => 'navn er her']);
    $url = route('brands.aliases.test', $w->brand);
    $data = ['entity' => 'brand', 'alias' => 'Navn', 'match_mode' => 'exact_word'];
    $this->postJson($url, $data)->assertOk()->assertJsonPath('window_days', 30)->assertJsonPath('scanned', 2)
        ->assertJsonPath('matches', 1)->assertJsonPath('samples.0.excerpt.match', 'Navn')
        ->assertJsonPath('samples.0.excerpt.before', 'ÆØ ')->assertJsonPath('samples.0.engine', $w->engine->key)
        ->assertJsonPath('conflicts.0', ['type' => 'competitor', 'name' => $w->c1->name, 'count' => 1])->assertJsonCount(1, 'conflicts');
    $this->postJson($url, array_replace($data, ['entity' => 'competitor:'.$w->c1->id, 'match_mode' => 'case_insensitive']))->assertOk()->assertJsonPath('matches', 2)->assertJsonCount(0, 'conflicts');
    $this->postJson($url, array_replace($data, ['alias' => 'nav[nm]', 'match_mode' => 'regex']))->assertOk()->assertJsonPath('matches', 2);
    $this->postJson($url, array_replace($data, ['alias' => '(*FAIL)', 'match_mode' => 'regex']))->assertUnprocessable()->assertJsonValidationErrors('alias');
});

it('scans at most the newest 2000 answers and returns at most three samples', function () {
    $w = $this->world;
    $w->answer($w->p1, now()->subDays(2)->toDateTimeString())->update(['answer_text' => 'Older answer']);
    // One response per run and one run per batch, as in collection. Insert the synthetic cohort in one query.
    DB::statement(<<<'SQL'
        WITH batches AS (
            INSERT INTO run_batches (tenant_id, market_id, scheduled_for)
            SELECT ?, ?, now() - n * interval '1 second' FROM generate_series(1, 2000) AS n RETURNING id, tenant_id
        ), cohort AS (
            INSERT INTO runs (tenant_id, run_batch_id, prompt_id, engine_key)
            SELECT tenant_id, id, ?, ? FROM batches RETURNING id, tenant_id, engine_key
        )
        INSERT INTO responses (tenant_id, run_id, engine_key, answer_text, collected_at)
        SELECT tenant_id, id, engine_key, 'Recent fixture', now() - interval '1 hour' FROM cohort
        SQL, [$w->tenant->id, $w->market->id, $w->p1->id, $w->engine->key]);
    $url = route('brands.aliases.test', $this->brand);
    $data = ['entity' => 'brand', 'match_mode' => 'exact_word', 'alias' => 'Recent'];
    $this->postJson($url, $data)->assertOk()->assertJsonPath('scanned', 2000)->assertJsonPath('matches', 2000)->assertJsonCount(3, 'samples');
    $this->postJson($url, array_replace($data, ['alias' => 'Older']))->assertOk()->assertJsonPath('matches', 0);
});

it('scopes linked resources to the brand and rate limits alias previews per user', function () {
    $market = Market::factory()->create();
    $competitor = Competitor::factory()->for($market->brand)->create();
    $data = ['entity' => 'competitor:'.$competitor->id, 'alias' => 'Fixture', 'match_mode' => 'exact_word'];
    $this->postJson(route('brands.markets.store', $this->brand), ['country' => 'IS', 'language' => 'is', 'copy_from' => $market->id])->assertNotFound();
    $this->patchJson(route('brands.markets.update', [$this->brand, $market]), ['is_active' => false])->assertNotFound();
    $url = route('brands.aliases.test', $this->brand);
    $this->postJson($url, $data)->assertNotFound();
    $data['entity'] = 'brand';
    for ($i = 1; $i < 30; $i++) {
        $this->postJson($url, $data)->assertOk();
    }
    $this->postJson(route('brands.aliases.test', $market->brand_id), $data)->assertTooManyRequests();
    $reader = User::factory()->create();
    $this->world->tenant->users()->attach($reader, ['role' => 'reader']);
    $reader->switchTeam($this->world->tenant);
    $this->actingAs($reader)->postJson($url, $data)->assertOk()->assertJsonPath('scanned', 0);
});
