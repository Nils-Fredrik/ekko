<?php

use App\Detection\DetectionCatalog;
use App\Engines\RawResponseStore;
use App\Metrics\DailyAggregator;
use App\Metrics\MetricsFilter;
use App\Metrics\MetricsService;
use App\Models\AggDaily;
use App\Models\AggSourceDaily;
use App\Models\AiReferralDaily;
use App\Models\BotHitDaily;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\CompetitorAlias;
use App\Models\CompetitorDomain;
use App\Models\Market;
use App\Models\Mention;
use App\Models\Plan;
use App\Models\Prompt;
use App\Models\User;
use App\Prompts\PersonalData;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Tests\Support\Metrics\MetricsWorld;

test('the prompt table shows visibility, position and stability, and hides another brand', function () {
    $world = MetricsWorld::create();
    $today = CarbonImmutable::now('Europe/Oslo')->toDateString();
    AggDaily::factory()->create([
        'market_id' => $world->market->id,
        'engine_key' => $world->engine->key,
        'prompt_id' => $world->p1->id,
        'date' => $today,
        'responses' => 20,
        'brand_mentioned' => 10,
        'sum_position' => 20,
        'count_position' => 10,
    ]);
    $other = Brand::factory()->create(['name' => 'Annen merkevare']);
    Prompt::factory()->for(Market::factory()->for($other))->create(['text' => 'Hemmelig prompt']);

    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);

    $this->actingAs($owner)->get(route('brands.prompts.index', $world->brand))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Brands/Prompts')
            ->where('prompts', function ($prompts) use ($world) {
                $row = collect($prompts)->firstWhere('id', $world->p1->id);
                expect($row['visibility'])->toBe(['value' => 0.5, 'n' => 20, 'low_sample' => false])
                    ->and($row['position']['value'])->toEqual(2)
                    ->and($row['position']['n'])->toBe(10)
                    ->and($row['stability'][0]['metric'])->toBe(['value' => 0.5, 'n' => 1, 'low_sample' => true])
                    ->and(collect($prompts)->pluck('text'))->not->toContain('Hemmelig prompt');

                return true;
            }));
});

test('a prompt shows highlighted mentions, sources and a raw answer', function () {
    $world = MetricsWorld::create();
    $text = 'Vi anbefaler Kaffebønna fremfor Fjordkaffe, og fjordkaffe igjen.';
    $response = $world->answer($world->p1, now()->toIso8601String());
    $response->update(['answer_text' => $text]);
    $world->brand->aliases()->create(['alias' => 'Fjordkaffe', 'match_mode' => 'case_insensitive']);
    Mention::factory()->create([
        'response_id' => $response->id,
        'entity_type' => 'unknown',
        'surface_name' => 'Kaffebønna',
        'first_char_offset' => mb_strpos($text, 'Kaffebønna'),
    ]);
    Mention::factory()->create([
        'response_id' => $response->id,
        'entity_type' => 'brand',
        'entity_id' => $world->brand->id,
        'surface_name' => 'Fjordkaffe',
        'first_char_offset' => mb_strpos($text, 'Fjordkaffe'),
    ]);
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);

    $this->actingAs($owner)->get(route('brands.prompts.show', [$world->brand, $world->p1]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Brands/PromptShow')
            ->where('engines.0.answers', function ($answers) {
                return collect($answers)->contains(function ($answer) {
                    $brand = collect($answer['segments'])->where('kind', 'brand')->pluck('text');

                    return $brand->all() === ['Fjordkaffe', 'fjordkaffe']
                        && collect($answer['segments'])->contains(
                            fn ($part) => $part['kind'] === 'unknown' && $part['text'] === 'Kaffebønna',
                        );
                });
            }));

    $this->get(route('brands.responses.raw', [$world->brand, $response]))
        ->assertOk()
        ->assertSee('Kaffebønna');
});

test('an unknown brand that recurs can be added as a competitor in one click', function () {
    $world = MetricsWorld::create();
    foreach ([1, 2] as $ignored) {
        $response = $world->answer($world->p1, now()->toIso8601String());
        Mention::factory()->create([
            'response_id' => $response->id,
            'entity_type' => 'unknown',
            'surface_name' => 'Kaffebønna',
            'first_char_offset' => 0,
        ]);
    }
    CompetitorAlias::factory()->for($world->c1)->create(['alias' => 'Nordlys']);
    CompetitorDomain::factory()->for($world->c1)->create(['domain' => 'nordlys.example.test']);

    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);

    $this->actingAs($owner)->get(route('brands.competitors.index', $world->brand))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('suggestions', [['name' => 'Kaffebønna', 'responses' => 2]])
            ->where('competitors', fn ($rows) => collect($rows)->firstWhere('name', 'Nordlys')['domains'] === ['nordlys.example.test']));

    $this->post(route('brands.competitors.store', $world->brand), ['name' => 'Kaffebønna'])->assertRedirect();
    $this->post(route('brands.competitors.store', $world->brand), ['name' => 'Kaffebønna'])->assertSessionHasErrors('name');

    app(TenantContext::class)->run($world->tenant->id, function () use ($world) {
        $competitor = Competitor::query()->where('brand_id', $world->brand->id)->where('name', 'Kaffebønna')->first();
        expect($competitor)->not->toBeNull()
            ->and(Competitor::query()->where('brand_id', $world->brand->id)->where('name', 'Kaffebønna')->count())->toBe(1)
            ->and($competitor->aliases()->where('alias', 'Kaffebønna')->where('match_mode', 'case_insensitive')->exists())->toBeTrue();
    });

    $this->get(route('brands.competitors.index', $world->brand))
        ->assertInertia(fn ($page) => $page->where('suggestions', []));
});

test('a pitch cannot add a fourth competitor, but a converted customer can', function () {
    $world = MetricsWorld::create();
    $world->tenant->forceFill(['kind' => 'pitch', 'pitch_expires_at' => now()->addDays(7)])->save();
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);

    $this->actingAs($owner)->post(route('brands.competitors.store', $world->brand), ['name' => 'Third'])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('brands.competitors.store', $world->brand), ['name' => 'Fourth'])->assertSessionHasErrors('name');
    expect($world->brand->competitors()->count())->toBe(3);

    $world->tenant->forceFill(['kind' => 'customer'])->save();
    $this->post(route('brands.competitors.store', $world->brand), ['name' => 'Fourth'])->assertRedirect()->assertSessionHasNoErrors();
    expect($world->brand->competitors()->count())->toBe(4);
});

test('an editor can delete only a competitor of the selected brand', function () {
    $world = MetricsWorld::create();
    $editor = User::factory()->create();
    $world->tenant->users()->attach($editor, ['role' => 'editor']);
    $editor->switchTeam($world->tenant);
    $otherBrand = Brand::factory()->create();
    $url = route('brands.competitors.destroy', [$world->brand, $world->c1]);
    $index = route('brands.competitors.index', $world->brand);

    $this->actingAs($editor)->delete(route('brands.competitors.destroy', [$otherBrand, $world->c1]))->assertNotFound();
    $this->from($index)->delete($url)->assertRedirect($index);
    $this->delete($url)->assertNotFound();
    $this->get($index.'?competitor='.$world->c1->id)->assertInertia(fn ($page) => $page
        ->has('competitors', 1)->where('competitors.0.id', $world->c2->id)
        ->missing('filters.competitor'));
    expect($world->c1->activitiesAsSubject()->where('event', 'deleted')->where('causer_id', $editor->id)->count())->toBe(1);
});

test('deleting a competitor stops new detection and preserves historical measurements', function () {
    $world = MetricsWorld::create();
    $competitor = $world->c1;
    $domain = 'nordlys.example.test';
    CompetitorAlias::factory()->for($competitor)->create(['alias' => $competitor->name]);
    CompetitorDomain::factory()->for($competitor)->create(['domain' => $domain]);
    $response = $world->answer($world->p1, '2026-11-10T10:00:00Z', [
        'competitors' => [$competitor->id => 1],
        'citations' => [[$domain, 'competitor']],
    ]);
    $response->citations()->update(['competitor_id' => $competitor->id]);
    $response->mentions()->update(['sentiment' => 0.8]);

    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);
    $index = route('brands.competitors.index', [$world->brand, 'from' => '2026-11-10', 'to' => '2026-11-10']);
    app(DailyAggregator::class)->aggregate('2026-11-10');
    $this->actingAs($owner)->get($index)->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($data) => $data
        ->where('chartData.series.0.entities', fn ($entities) => collect($entities)->pluck('name')->contains($competitor->name))));
    $this->delete(route('brands.competitors.destroy', [$world->brand, $competitor]))->assertRedirect();
    $this->get($index)->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($data) => $data
        ->where('chartData.series.0.entities', fn ($entities) => collect($entities)->pluck('name')->doesntContain($competitor->name))));

    $catalog = DetectionCatalog::load();
    expect($catalog->aliases)->toBeEmpty()
        ->and($catalog->domains->classify($domain)['ownership'])->toBe('third_party')
        ->and($response->citations()->sole()->competitor->id)->toBe($competitor->id);
    app(DailyAggregator::class)->aggregate('2026-11-10');
    expect(AggDaily::query()->sole()->competitor_mentions)->toEqual([$competitor->id => 1]);

    $metrics = app(MetricsService::class);
    $filter = new MetricsFilter($world->brand->id, '2026-11-10', '2026-11-10');
    expect($metrics->sourceGapDetail($filter, $domain)['competitors'])->toBeEmpty()
        ->and(collect($metrics->sentimentDistribution($filter)['rows'])->pluck('key'))->not->toContain('competitor:'.$competitor->id);

    $this->post(route('brands.competitors.store', $world->brand), ['name' => $competitor->name])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect($world->brand->competitors()->where('name', $competitor->name)->sole()->id)->not->toBe($competitor->id);
});

test('prompts can be edited, exported and imported, and a reader cannot change them', function () {
    $world = MetricsWorld::create();
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);
    $reader = User::factory()->create();
    $world->tenant->users()->attach($reader, ['role' => 'reader']);
    $reader->switchTeam($world->tenant);

    $this->actingAs($owner)
        ->patch(route('brands.prompts.update', [$world->brand, $world->p1]), ['text' => 'Hvem maler kaffen best?', 'is_active' => false])
        ->assertRedirect();

    app(TenantContext::class)->run($world->tenant->id, function () use ($world) {
        $world->p1->refresh();
        expect($world->p1->text)->toBe('Hvem maler kaffen best?')->and($world->p1->is_active)->toBeFalse();
    });

    $export = $this->actingAs($owner)->get(route('brands.prompts.export', $world->brand))->assertOk();
    expect($export->headers->get('content-type'))->toContain('text/csv')
        ->and($export->getContent())->toContain('Hvem maler kaffen best?');

    $this->actingAs($owner)
        ->patch(route('brands.prompts.update', [$world->brand, $world->p1]), ['text' => 'Skriv til ola@example.test'])
        ->assertRedirect();
    $this->actingAs($owner)->get(route('brands.prompts.index', $world->brand))
        ->assertInertia(fn ($page) => $page->where('personalData', true));

    $this->actingAs($owner)
        ->post(route('brands.prompts.import', $world->brand), ['csv' => "text,market\nHvem brenner kaffen?,NO\n"])
        ->assertRedirect();
    app(TenantContext::class)->run($world->tenant->id, function () use ($world) {
        expect(Prompt::query()->where('market_id', $world->market->id)->where('text', 'Hvem brenner kaffen?')->exists())->toBeTrue();
    });

    $other = Prompt::factory()->for(Market::factory()->for(Brand::factory()->create()))->create();
    $this->actingAs($owner)->get(route('brands.prompts.show', [$world->brand, $other]))->assertNotFound();

    $this->actingAs($reader)
        ->patch(route('brands.prompts.update', [$world->brand, $world->p1]), ['is_active' => true])
        ->assertForbidden();
    $this->actingAs($reader)
        ->post(route('brands.competitors.store', $world->brand), ['name' => 'Ny'])
        ->assertForbidden();
});

test('inline saves report duplicate text, and another brand in the tenant is not found', function () {
    $world = MetricsWorld::create();
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);
    $otherBrand = Brand::factory()->create();
    $otherPrompt = Prompt::factory()->for(Market::factory()->for($otherBrand))->create();
    $otherResponse = $world->answer($otherPrompt, now()->toIso8601String());

    $this->actingAs($owner)
        ->from(route('brands.prompts.index', $world->brand))
        ->patch(route('brands.prompts.update', [$world->brand, $world->p2]), ['text' => $world->p1->text])
        ->assertRedirect()
        ->assertSessionHasErrors('text');

    $this->actingAs($owner)
        ->patch(route('brands.prompts.update', [$world->brand, $otherPrompt]), ['text' => 'En annen formulering'])
        ->assertNotFound();
    $this->actingAs($owner)
        ->get(route('brands.responses.raw', [$world->brand, $otherResponse]))
        ->assertNotFound();
});

test('csv import shows a personal-data warning for the imported row', function () {
    $world = MetricsWorld::create();
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);

    $this->actingAs($owner)
        ->post(route('brands.prompts.import', $world->brand), ['csv' => "text,market\nSkriv til ola@example.test,NO\n"])
        ->assertRedirect();

    $this->actingAs($owner)->get(route('brands.prompts.index', $world->brand))
        ->assertInertia(fn ($page) => $page->where(
            'importResult.warnings.0',
            __('app.library.personal_data_row', ['row' => 2]),
        ));
});

test('csv import rejects a file that is not utf-8, and a row that is empty of usable text', function () {
    $world = MetricsWorld::create();
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);

    $this->actingAs($owner)
        ->post(route('brands.prompts.import', $world->brand), ['csv' => "text,market\nKaff\xE6,NO\nHvem brenner den?,NO\n"])
        ->assertRedirect();

    $this->actingAs($owner)->get(route('brands.prompts.index', $world->brand))
        ->assertInertia(fn ($page) => $page->where('importResult.errors.0', __('app.library.bad_encoding')));

    app(TenantContext::class)->run($world->tenant->id, function () use ($world) {
        expect(Prompt::query()->where('market_id', $world->market->id)->where('text', 'Hvem brenner den?')->exists())->toBeFalse();
    });

    $this->actingAs($owner)
        ->post(route('brands.prompts.import', $world->brand), ['csv' => "text,market\nKaffe\x00,NO\n".str_repeat('a', Prompt::TEXT_MAX + 1).",NO\nHvem brenner den?,NO\n"])
        ->assertRedirect();

    $this->actingAs($owner)->get(route('brands.prompts.index', $world->brand))
        ->assertInertia(fn ($page) => $page
            ->where('importResult.errors.0', __('app.library.row_error', ['row' => 2, 'message' => __('app.library.invalid_row')]))
            ->where('importResult.errors.1', __('app.library.row_error', ['row' => 3, 'message' => __('app.library.invalid_row')]))
            ->where('importResult.created', 1));

    $rows = str_repeat(",NO\n", 1001);
    $this->actingAs($owner)
        ->post(route('brands.prompts.import', $world->brand), ['csv' => "text,market\n".$rows])
        ->assertRedirect();

    $this->actingAs($owner)->get(route('brands.prompts.index', $world->brand))
        ->assertInertia(fn ($page) => $page->where(
            'importResult.errors',
            fn ($errors) => collect($errors)->contains(__('app.library.row_error', ['row' => 1002, 'message' => __('app.library.too_many_rows')]))
                && collect($errors)->doesntContain(fn ($error) => str_contains((string) $error, __('app.library.quota'))),
        ));
});

test('inline editing accepts the same prompt length as the api and rejects a null byte', function () {
    $world = MetricsWorld::create();
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);
    $text = str_repeat('a', Prompt::TEXT_MAX);

    $this->actingAs($owner)
        ->patch(route('brands.prompts.update', [$world->brand, $world->p1]), ['text' => $text])
        ->assertRedirect();

    app(TenantContext::class)->run($world->tenant->id, function () use ($world, $text) {
        expect($world->p1->refresh()->text)->toBe($text);
    });

    $this->actingAs($owner)
        ->patch(route('brands.prompts.update', [$world->brand, $world->p1]), ['text' => $text.'a'])
        ->assertSessionHasErrors('text');
    $this->actingAs($owner)
        ->patch(route('brands.prompts.update', [$world->brand, $world->p1]), ['text' => "kaf\0fe"])
        ->assertSessionHasErrors('text');
});

test('reactivation and csv import count only active prompts against the plan', function () {
    $world = MetricsWorld::create();
    $world->tenant->forceFill(['plan_id' => Plan::factory()->create(['max_prompts_per_market' => 1])->id])->save();
    $world->p2->update(['is_active' => false]);
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);

    $this->actingAs($owner)
        ->from(route('brands.prompts.index', $world->brand))
        ->patch(route('brands.prompts.update', [$world->brand, $world->p2]), ['is_active' => true])
        ->assertRedirect()
        ->assertSessionHasErrors('is_active');
    expect($world->p2->fresh()->is_active)->toBeFalse();

    $this->actingAs($owner)
        ->patch(route('brands.prompts.update', [$world->brand, $world->p1]), ['text' => 'Hvem maler kaffen best?'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($owner)
        ->post(route('brands.prompts.import', $world->brand), ['csv' => "text,market,active\nEn inaktiv?,NO,0\nEn aktiv?,NO,1\n"])
        ->assertRedirect();

    app(TenantContext::class)->run($world->tenant->id, function () use ($world) {
        expect(Prompt::query()->where('market_id', $world->market->id)->where('text', 'En inaktiv?')->where('is_active', false)->exists())->toBeTrue()
            ->and(Prompt::query()->where('text', 'En aktiv?')->exists())->toBeFalse();
    });
});

test('csv import tells markets in the same country apart by language', function () {
    $world = MetricsWorld::create();
    $nynorsk = Market::factory()->for($world->brand)->create(['country' => 'NO', 'language' => 'nn']);
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);

    $this->actingAs($owner)
        ->post(route('brands.prompts.import', $world->brand), ['csv' => "text,market\nKva er best kaffi?,NO\n"])
        ->assertRedirect();
    $this->actingAs($owner)
        ->post(route('brands.prompts.import', $world->brand), ['csv' => "text,market,language\nKva er best kaffi?,NO,nn\n"])
        ->assertRedirect();

    app(TenantContext::class)->run($world->tenant->id, function () use ($world, $nynorsk) {
        $prompt = Prompt::query()->where('text', 'Kva er best kaffi?')->first();
        expect($prompt)->not->toBeNull()->and($prompt->market_id)->toBe($nynorsk->id)
            ->and(Prompt::query()->where('market_id', $world->market->id)->where('text', 'Kva er best kaffi?')->exists())->toBeFalse();
    });

    $export = $this->actingAs($owner)->get(route('brands.prompts.export', $world->brand))->assertOk()->getContent();
    expect($export)->toContain('language')->and($export)->toContain('market_id')->and($export)->toContain(',nn,');
});

test('a raw download stays inside the tenant prefix of the configured bucket', function () {
    $world = MetricsWorld::create();
    config(['engines.raw_bucket' => 'ekko-raw']);
    $response = $world->answer($world->p1, now()->toIso8601String());
    $response->update(['answer_text' => 'lokal tekst', 'raw_uri' => 'gs://ekko-raw/'.$world->tenant->id.'/2026/11/1.json']);
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);

    $store = Mockery::mock(RawResponseStore::class);
    $store->shouldReceive('read')->once()->with('gs://ekko-raw/'.$world->tenant->id.'/2026/11/1.json')->andReturn('{"from":"store"}');
    app()->instance(RawResponseStore::class, $store);

    $this->actingAs($owner)->get(route('brands.responses.raw', [$world->brand, $response]))
        ->assertOk()
        ->assertSee('store');

    $response->update(['raw_uri' => 'gs://other-bucket/'.$world->tenant->id.'/2026/11/1.json']);
    $blocked = Mockery::mock(RawResponseStore::class);
    $blocked->shouldNotReceive('read');
    app()->instance(RawResponseStore::class, $blocked);

    $this->actingAs($owner)->get(route('brands.responses.raw', [$world->brand, $response]))
        ->assertOk()
        ->assertSee('lokal tekst');

    $response->update(['raw_uri' => 'gs://ekko-raw/999999/2026/11/1.json']);
    $this->actingAs($owner)->get(route('brands.responses.raw', [$world->brand, $response]))
        ->assertOk()
        ->assertSee('lokal tekst');
});

test('personal data warnings cover spaced phone numbers', function () {
    expect(PersonalData::in('Ring 412 34 567'))->toBeTrue()
        ->and(PersonalData::in('Ring +47 412 34 567'))->toBeTrue()
        ->and(PersonalData::in('Ring +47 41234567'))->toBeTrue()
        ->and(PersonalData::in('ola@example.test'))->toBeTrue()
        ->and(PersonalData::in('Hvem maler kaffen best?'))->toBeFalse();
});

test('sources and crawlers read MetricsService', function () {
    $world = MetricsWorld::create();
    $today = CarbonImmutable::now('Europe/Oslo')->toDateString();
    AggDaily::factory()->create([
        'market_id' => $world->market->id,
        'engine_key' => $world->engine->key,
        'prompt_id' => $world->p1->id,
        'date' => $today,
        'responses' => 20,
        'competitor_only_responses' => 10,
    ]);
    AggSourceDaily::factory()->create([
        'market_id' => $world->market->id,
        'brand_id' => $world->brand->id,
        'engine_key' => $world->engine->key,
        'domain' => 'kaffe.example.test',
        'date' => $today,
        'citations' => 4,
        'responses_with_domain' => 4,
        'gap_responses' => 2,
    ]);
    BotHitDaily::factory()->create([
        'brand_id' => $world->brand->id,
        'date' => $today,
        'bot' => 'GPTBot',
        'hits' => 8,
    ]);
    AiReferralDaily::factory()->create([
        'brand_id' => $world->brand->id,
        'date' => $today,
        'source' => 'chatgpt.com',
        'sessions' => 10,
        'engaged_sessions' => 6,
        'conversions' => '1.0000',
    ]);

    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);

    $this->actingAs($owner)->get(route('brands.sources', $world->brand))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Brands/Sources')
            ->missing('chartData')
            ->loadDeferredProps(fn ($deferred) => $deferred
                ->where('chartData.sources.0.domain', 'kaffe.example.test')
                ->where('chartData.sources.0.share', 0.2)
                ->where('chartData.sources.0.n', 20)
                ->where('chartData.gaps.0.gap_rate', 0.2)
                ->where('chartData.gaps.0.n', 10)));

    $this->actingAs($owner)->get(route('brands.crawlers', $world->brand))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Brands/Crawlers')
            ->where('bots.0.bot', 'GPTBot')
            ->where('bots.0.hits', 8)
            ->where('traffic.0.source', 'chatgpt.com')
            ->where('traffic.0.sessions', 10));
});

test('csv export keeps formula-like prompt text from being evaluated in a spreadsheet', function () {
    $world = MetricsWorld::create();
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);

    $this->actingAs($owner)
        ->patch(route('brands.prompts.update', [$world->brand, $world->p1]), ['text' => '=1+1'])
        ->assertRedirect();

    $export = $this->actingAs($owner)->get(route('brands.prompts.export', $world->brand))->assertOk()->getContent();
    expect($export)->toContain("'=1+1")->and($export)->not->toContain("\n=1+1");

    $this->actingAs($owner)
        ->post(route('brands.prompts.import', $world->brand), ['csv' => "text,market\n'=2+2,NO\n'=1+1,NO\n"])
        ->assertRedirect();

    app(TenantContext::class)->run($world->tenant->id, function () use ($world) {
        expect(Prompt::query()->where('market_id', $world->market->id)->where('text', '=2+2')->exists())->toBeTrue()
            ->and(Prompt::query()->where('text', "'=1+1")->exists())->toBeFalse()
            ->and(Prompt::query()->where('market_id', $world->market->id)->where('text', '=1+1')->count())->toBe(1);
    });
});

test('csv export keeps a leading apostrophe in front of a formula', function () {
    $world = MetricsWorld::create();
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);

    $this->actingAs($owner)
        ->patch(route('brands.prompts.update', [$world->brand, $world->p1]), ['text' => "'=1+1"])
        ->assertRedirect();

    $export = $this->actingAs($owner)->get(route('brands.prompts.export', $world->brand))->assertOk()->getContent();
    expect($export)->toContain("''=1+1");

    $this->actingAs($owner)
        ->patch(route('brands.prompts.update', [$world->brand, $world->p1]), ['text' => 'Midlertidig'])
        ->assertRedirect();
    $this->actingAs($owner)
        ->post(route('brands.prompts.import', $world->brand), ['csv' => $export])
        ->assertRedirect();

    app(TenantContext::class)->run($world->tenant->id, function () use ($world) {
        $texts = Prompt::query()->where('market_id', $world->market->id)->pluck('text');

        expect($texts)->toContain("'=1+1")
            ->and($texts)->not->toContain('=1+1');
    });
});

test('csv export keeps a trailing backslash from joining the next prompt', function () {
    $world = MetricsWorld::create();
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);

    $this->actingAs($owner)
        ->patch(route('brands.prompts.update', [$world->brand, $world->p1]), ['text' => 'What about C:\\'])
        ->assertRedirect();
    $this->actingAs($owner)
        ->patch(route('brands.prompts.update', [$world->brand, $world->p2]), ['text' => 'Neste prompt'])
        ->assertRedirect();

    $export = $this->actingAs($owner)->get(route('brands.prompts.export', $world->brand))->assertOk()->getContent();

    $this->actingAs($owner)
        ->patch(route('brands.prompts.update', [$world->brand, $world->p1]), ['text' => 'Midlertidig'])
        ->assertRedirect();
    $this->actingAs($owner)
        ->patch(route('brands.prompts.update', [$world->brand, $world->p2]), ['text' => 'Midlertidig to'])
        ->assertRedirect();
    $this->actingAs($owner)
        ->post(route('brands.prompts.import', $world->brand), ['csv' => $export])
        ->assertRedirect();

    app(TenantContext::class)->run($world->tenant->id, function () use ($world) {
        $texts = Prompt::query()->where('market_id', $world->market->id)->pluck('text');

        expect($texts)->toContain('What about C:\\')
            ->and($texts)->toContain('Neste prompt')
            ->and($texts->contains(fn (string $text): bool => str_contains($text, 'What about C:\\') && str_contains($text, 'Neste prompt')))->toBeFalse();
    });
});
