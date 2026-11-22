<?php

use App\Dashboard\SentimentResponses;
use App\Metrics\DailyAggregator;
use App\Metrics\MetricsFilter;
use App\Metrics\MetricsService;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\Market;
use App\Models\Mention;
use App\Models\Prompt;
use App\Models\Response;
use App\Models\Team;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Metrics\MetricsWorld;

// U28: the answers behind the sentiment on the competitors page, with the same counts as the table.

/**
 * One answer whose text names the brand and Nordlys, with the given sentiment for each (null: not mentioned,
 * 'none': mentioned without a sentiment).
 *
 * @param  array<string, mixed>  $options
 */
function sentimentAnswer(MetricsWorld $world, string $collectedAt, float|string|null $brand, float|string|null $nordlys, array $options = []): Response
{
    $prompt = $options['prompt'] ?? $world->p1;
    $response = $world->answer($prompt, $collectedAt, [
        'status' => $options['status'] ?? 'extracted',
        ...($brand === null ? [] : ['brand' => ['sent' => $brand === 'none' ? null : $brand]]),
        'competitors' => $nordlys === null ? [] : [$world->c1->id => null],
    ]);
    $text = 'Blant kaffebrenneriene i Bergen nevnes ofte Fjordkaffe for jevn kvalitet, mens Nordlys får blandede omtaler for service.';
    $response->update(['answer_text' => $text]);
    $response->mentions()->where('entity_type', 'brand')->update(['surface_name' => 'Fjordkaffe', 'first_char_offset' => mb_strpos($text, 'Fjordkaffe')]);
    $response->mentions()->where('entity_type', 'competitor')->update([
        'surface_name' => 'Nordlys', 'first_char_offset' => mb_strpos($text, 'Nordlys'),
        'sentiment' => $nordlys === 'none' ? null : $nordlys,
    ]);

    return $response;
}

function sentimentWorld(): MetricsWorld
{
    $world = MetricsWorld::create();
    // 2026-11-10 in Oslo: from 2026-11-09T23:00Z to 2026-11-10T23:00Z.
    sentimentAnswer($world, '2026-11-09T23:30:00Z', 0.6, -0.4);
    sentimentAnswer($world, '2026-11-10T08:00:00Z', 0.0, -0.8);
    sentimentAnswer($world, '2026-11-10T09:00:00Z', -0.2, 0.0);
    sentimentAnswer($world, '2026-11-10T10:00:00Z', 0.9, 0.3, ['prompt' => $world->p2]);
    sentimentAnswer($world, '2026-11-10T11:00:00Z', 'none', 'none');
    // Outside the selection: a pending extraction, the next Oslo day, and the day before.
    sentimentAnswer($world, '2026-11-10T12:00:00Z', -0.9, -0.9, ['status' => 'collected']);
    sentimentAnswer($world, '2026-11-10T23:30:00Z', -0.9, -0.9);
    sentimentAnswer($world, '2026-11-09T22:30:00Z', -0.9, -0.9);

    foreach (['2026-11-09', '2026-11-10', '2026-11-11'] as $date) {
        app(DailyAggregator::class)->aggregate($date);
    }
    $world->tenant->owner->switchTeam($world->tenant);
    Cache::flush();

    return $world;
}

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'Europe/Oslo')));

test('the counts are the same as the sentiment distribution of the competitors table', function () {
    $world = sentimentWorld();
    $period = ['from' => '2026-11-10', 'to' => '2026-11-10'];
    $distribution = collect(app(MetricsService::class)->sentimentDistribution(
        new MetricsFilter($world->brand->id, '2026-11-10', '2026-11-10'),
    )['rows'])->keyBy('key');

    foreach (['brand' => 'own', (string) $world->c1->id => 'competitor:'.$world->c1->id] as $entity => $key) {
        $row = $distribution->get($key);
        $this->actingAs($world->tenant->owner)
            ->get(route('brands.responses.index', [$world->brand, ...$period, 'entity' => $entity]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Brands/Responses')
                ->where('entity.key', (string) $entity)
                ->where('counts', ['negative' => $row['negative'], 'neutral' => $row['neutral'], 'positive' => $row['positive'], 'total' => $row['n']])
                ->has('answers', $row['n']));
    }

    expect($distribution->get('own'))->toMatchArray(['negative' => 1, 'neutral' => 1, 'positive' => 2, 'n' => 4])
        ->and($distribution->get('competitor:'.$world->c1->id))->toMatchArray(['negative' => 2, 'neutral' => 1, 'positive' => 1, 'n' => 4]);

    // The table on the competitors page shows the same n.
    $this->actingAs($world->tenant->owner)
        ->get(route('brands.competitors.index', [$world->brand, ...$period]))
        ->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($deferred) => $deferred
            ->where('chartData.table', fn ($rows) => collect($rows)->firstWhere('key', (string) $world->c1->id)['sentiment']['n'] === 4
                && collect($rows)->firstWhere('key', 'brand')['sentiment']['n'] === 4)));
});

test('one sentiment group lists its answers newest first with the prompt and an excerpt', function () {
    $world = sentimentWorld();

    $this->actingAs($world->tenant->owner)
        ->get(route('brands.responses.index', [$world->brand, 'from' => '2026-11-10', 'to' => '2026-11-10',
            'entity' => $world->c1->id, 'sentiment' => 'negative']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sentiment', 'negative')
            ->where('entity.name', 'Nordlys')
            ->has('answers', 2)
            ->where('answers.0.sentiment', -0.8)
            ->where('answers.1.sentiment', -0.4)
            ->where('answers.0.prompt.id', $world->p1->id)
            ->where('answers.0.prompt.url', route('brands.prompts.show', [$world->brand, $world->p1]))
            ->where('answers.0.excerpt.match', 'Nordlys')
            ->where('answers.0.excerpt.after', ' får blandede omtaler for service.')
            ->where('answers.0.segments', fn ($segments) => collect($segments)->contains(['text' => 'Nordlys', 'kind' => 'competitor']))
            ->where('pagination.total', 2));
});

test('the period, engine and topic filters narrow the list', function () {
    $world = sentimentWorld();
    $topic = $world->brand->topics()->create(['name' => 'Service']);
    $world->p2->update(['topic_id' => $topic->id]);

    $this->actingAs($world->tenant->owner)
        ->get(route('brands.responses.index', [$world->brand, 'from' => '2026-11-10', 'to' => '2026-11-10', 'topic' => $topic->id]))
        ->assertInertia(fn ($page) => $page
            ->where('analysisFilters.query.topic', $topic->id)
            ->where('counts', ['negative' => 0, 'neutral' => 0, 'positive' => 1, 'total' => 1]));

    $this->actingAs($world->tenant->owner)
        ->get(route('brands.responses.index', [$world->brand, 'from' => '2026-11-11', 'to' => '2026-11-11']))
        ->assertInertia(fn ($page) => $page->where('counts.total', 1)->where('counts.negative', 1));

    $this->actingAs($world->tenant->owner)
        ->get(route('brands.responses.index', [$world->brand, 'from' => '2026-11-10', 'to' => '2026-11-10', 'engine' => 'missing']))
        ->assertInertia(fn ($page) => $page->missing('analysisFilters.query.engine')->where('counts.total', 4));
});

test('a competitor of another brand or another tenant is not found', function () {
    $world = sentimentWorld();
    $owner = $world->tenant->owner;
    $otherBrand = Brand::factory()->create();
    $sibling = Competitor::factory()->for($otherBrand)->create();
    $foreign = app(TenantContext::class)->run(Team::factory()->tenant()->create()->id, function (): int {
        $brand = Brand::factory()->create();
        Market::factory()->for($brand)->create();

        return Competitor::factory()->for($brand)->create()->id;
    });

    foreach ([$sibling->id, $foreign, 'abc', '12x'] as $entity) {
        $this->actingAs($owner)->get(route('brands.responses.index', [$world->brand, 'entity' => $entity]))->assertNotFound();
    }
    $this->actingAs($owner)->get(route('brands.responses.index', [$world->brand, 'sentiment' => 'angry']))->assertSessionHasErrors('sentiment');
});

test('the excerpt is cut at whole words around the mention', function () {
    $text = str_repeat('ord ', 80).'Nordlys er best'.str_repeat(' tekst', 80);
    $excerpt = SentimentResponses::excerpt($text, 'Nordlys', mb_strpos($text, 'Nordlys'));

    expect($excerpt['match'])->toBe('Nordlys')
        ->and($excerpt['cut_start'])->toBeTrue()
        ->and($excerpt['cut_end'])->toBeTrue()
        ->and($excerpt['before'])->toStartWith('ord ')
        ->and($excerpt['after'])->toEndWith('tekst')
        ->and(SentimentResponses::excerpt('Kort svar om Nordlys.', 'Nordlys', null))
        ->toBe(['before' => 'Kort svar om ', 'match' => 'Nordlys', 'after' => '.', 'cut_start' => false, 'cut_end' => false])
        // An offset that does not point at the name falls back to a search.
        ->and(SentimentResponses::excerpt('Kort svar om Nordlys.', 'Nordlys', 3)['before'])->toBe('Kort svar om ')
        ->and(SentimentResponses::excerpt('Et svar uten navnet.', 'Nordlys', 0))
        ->toBe(['before' => '', 'match' => '', 'after' => 'Et svar uten navnet.', 'cut_start' => false, 'cut_end' => false]);
});

test('prompts of another brand in the tenant are left out', function () {
    $world = sentimentWorld();
    $otherBrand = Brand::factory()->create();
    $otherPrompt = Prompt::factory()->for(Market::factory()->for($otherBrand)->create())->create();
    $response = $world->answer($otherPrompt, '2026-11-10T08:00:00Z', ['competitors' => [$world->c1->id => null]]);
    Mention::query()->where('response_id', $response->id)->update(['sentiment' => -0.5]);

    $this->actingAs($world->tenant->owner)
        ->get(route('brands.responses.index', [$world->brand, 'from' => '2026-11-10', 'to' => '2026-11-10', 'entity' => $world->c1->id]))
        ->assertInertia(fn ($page) => $page->where('counts.total', 4));
});
