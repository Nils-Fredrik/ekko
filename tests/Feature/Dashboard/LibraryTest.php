<?php

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
use App\Models\Prompt;
use App\Models\User;
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
    $text = 'Vi anbefaler Kaffebønna fremfor Fjordkaffe.';
    $response = $world->answer($world->p1, now()->toIso8601String());
    $response->update(['answer_text' => $text]);
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
                return collect($answers)->contains(fn ($answer) => collect($answer['segments'])->contains(
                    fn ($part) => $part['kind'] === 'brand' && $part['text'] === 'Fjordkaffe',
                ) && collect($answer['segments'])->contains(
                    fn ($part) => $part['kind'] === 'unknown' && $part['text'] === 'Kaffebønna',
                ));
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

    app(TenantContext::class)->run($world->tenant->id, function () use ($world) {
        $competitor = Competitor::query()->where('brand_id', $world->brand->id)->where('name', 'Kaffebønna')->first();
        expect($competitor)->not->toBeNull()
            ->and($competitor->aliases()->where('alias', 'Kaffebønna')->where('match_mode', 'case_insensitive')->exists())->toBeTrue();
    });

    $this->get(route('brands.competitors.index', $world->brand))
        ->assertInertia(fn ($page) => $page->where('suggestions', []));
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
            ->where('share.0.domain', 'kaffe.example.test')
            ->where('share.0.share', 0.2)
            ->where('share.0.n', 20)
            ->where('gap.0.gap_rate', 0.2)
            ->where('gap.0.n', 10));

    $this->actingAs($owner)->get(route('brands.crawlers', $world->brand))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Brands/Crawlers')
            ->where('bots.0.bot', 'GPTBot')
            ->where('bots.0.hits', 8)
            ->where('traffic.0.source', 'chatgpt.com')
            ->where('traffic.0.sessions', 10));
});
