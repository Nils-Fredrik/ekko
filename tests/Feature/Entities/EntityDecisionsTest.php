<?php

use App\Models\BrandAlias;
use App\Models\Competitor;
use App\Models\CompetitorAlias;
use App\Models\EntityDecision;
use App\Models\Mention;
use App\Models\Team;
use App\Models\User;
use App\Prompts\EntityQueue;
use App\Prompts\QueueItem;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Metrics\MetricsWorld;

// U21: decisions on unknown brands (docs/ux-arbeidsflate.md 6.3, ADR 0043).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'Europe/Oslo'));
    $this->w = MetricsWorld::create();
    $this->w->tenant->owner->switchTeam($this->w->tenant);
});

/** $count answers collected at $at that name the unknown brand in the given spellings, one spelling per answer in turn. */
function namedAnswers(MetricsWorld $w, array $spellings, int $count, string $at = '2026-11-18 10:00:00'): void
{
    for ($i = 0; $i < $count; $i++) {
        $w->answer($w->p1, $at, ['brand' => ['pos' => 1], 'unknown' => $spellings[$i % count($spellings)]]);
    }
}

/** @return array<string, QueueItem> */
function queueOf(MetricsWorld $w): array
{
    $items = [];
    foreach (app(EntityQueue::class)->pending($w->brand->fresh()) as $item) {
        $items[$item->key] = $item;
    }

    return $items;
}

function decide(MetricsWorld $w, array $data)
{
    return test()->actingAs($w->tenant->owner)->postJson(route('brands.entities.decide', $w->brand), $data);
}

function unknownMentions(MetricsWorld $w, string $surface): int
{
    return Mention::query()->where('entity_type', 'unknown')->where('surface_name', $surface)->count();
}

/** @return list<string> */
function aggregationSignals(): array
{
    return DB::table('work_signals')->where('kind', 'aggregation')->orderBy('scope')->pluck('scope')->all();
}

test('the queue groups spellings by key, counts distinct answers and leaves out known, decided and rare names', function () {
    $w = $this->w;
    namedAnswers($w, ['Netthavn AS', 'Netthavn', 'netthavn.'], 6);
    // One answer that names two spellings counts once.
    $response = $w->answer($w->p2, '2026-11-18 11:00:00', ['unknown' => 'Netthavn']);
    Mention::factory()->create(['response_id' => $response->id, 'entity_type' => 'unknown', 'surface_name' => 'NETTHAVN', 'detection' => 'llm']);
    namedAnswers($w, ['Nordlys AS'], 3);     // a competitor's name: known
    namedAnswers($w, ['Brenneriet'], 1);     // one answer: too few
    namedAnswers($w, ['Kaffeslottet'], 2);
    EntityDecision::query()->create(['brand_id' => $w->brand->id, 'name_key' => 'kaffeslottet', 'display_name' => 'Kaffeslottet',
        'variants' => ['Kaffeslottet'], 'decision' => 'ignored', 'alias_ids' => [], 'decided_at' => now()]);
    // Older than 30 days: not in the queue.
    namedAnswers($w, ['Gammelbua'], 3, '2026-10-01 10:00:00');

    $queue = queueOf($w);

    expect(array_keys($queue))->toBe(['netthavn']);
    $item = $queue['netthavn'];
    expect($item->answers)->toBe(7)
        ->and($item->name)->toBe('Netthavn')
        ->and($item->variants)->toContain('Netthavn AS', 'netthavn.', 'NETTHAVN')
        ->and($item->markets)->toBe([$w->market->id])
        ->and($item->engines)->toBe([$w->engine->key])
        ->and($item->n)->toBe(13)
        ->and(round($item->share, 4))->toBe(round(7 / 13, 4))
        ->and($item->toArray())->toHaveKeys(['key', 'name', 'variants', 'answers', 'share', 'markets', 'engines', 'last_seen', 'suggestion']);
});

test('suggestions are rules: platforms are other actors, prefixes of known names are aliases, generic words are ignored', function () {
    $w = $this->w;
    namedAnswers($w, ['Shopify'], 2);
    namedAnswers($w, ['Nordlys Norge'], 2);
    namedAnswers($w, ['Fjordkaffe Bergen'], 2);
    namedAnswers($w, ['beste nettbutikk'], 2);
    namedAnswers($w, ['Beste Nettbutikk Oslo'], 2);
    namedAnswers($w, ['Havnelys Studio'], 2);

    $queue = queueOf($w);

    expect($queue['shopify']->suggestion)->toBe(['decision' => 'other'])
        ->and($queue['nordlys norge']->suggestion)->toBe(['decision' => 'alias', 'target_type' => 'competitor', 'target_id' => $w->c1->id, 'target_name' => 'Nordlys'])
        ->and($queue['fjordkaffe bergen']->suggestion)->toBe(['decision' => 'alias', 'target_type' => 'brand', 'target_id' => $w->brand->id, 'target_name' => 'Fjordkaffe'])
        ->and($queue['beste nettbutikk']->suggestion)->toBe(['decision' => 'ignored'])
        ->and($queue['beste nettbutikk oslo']->suggestion)->toBeNull()
        ->and($queue['havnelys studio']->suggestion)->toBeNull();
});

test('competitor creates the competitor with every spelling as alias, reclassifies 90 days and signals aggregation; undo reverses it', function () {
    $w = $this->w;
    namedAnswers($w, ['Havnelys Studio', 'Havnelys Studio AS'], 4);
    namedAnswers($w, ['Havnelys Studio'], 1, '2026-09-01 10:00:00');  // within 90 days
    namedAnswers($w, ['Havnelys Studio'], 1, '2026-07-01 10:00:00');  // older: stays unknown

    $response = decide($w, ['keys' => ['havnelys studio'], 'decision' => 'competitor'])->assertOk();

    $decision = EntityDecision::query()->sole();
    $competitor = Competitor::query()->where('name', 'Havnelys Studio')->sole();
    expect($response->json())->toBe(['decisions' => [['id' => $decision->id, 'key' => 'havnelys studio']], 'undo_ids' => [$decision->id]])
        ->and($competitor->is_active)->toBeTrue()
        ->and($competitor->position)->toBe(3)
        ->and($competitor->aliases()->orderBy('alias')->pluck('alias')->all())->toBe(['Havnelys Studio', 'Havnelys Studio AS'])
        ->and($competitor->aliases()->pluck('match_mode')->unique()->all())->toBe(['case_insensitive'])
        ->and($decision->created_competitor)->toBeTrue()
        ->and($decision->target_type)->toBe('competitor')
        ->and($decision->target_id)->toBe($competitor->id)
        ->and($decision->reclassified_mentions)->toBe(5)
        ->and(Mention::query()->where('entity_type', 'competitor')->where('entity_id', $competitor->id)->count())->toBe(5)
        ->and(unknownMentions($w, 'Havnelys Studio'))->toBe(1)
        ->and(aggregationSignals())->toBe(['2026-09-01', '2026-11-18'])
        ->and(queueOf($w))->toBe([])
        ->and($w->brand->fresh()->entities_reviewed_at)->not->toBeNull();

    DB::table('work_signals')->delete();
    $this->actingAs($w->tenant->owner)->deleteJson(route('brands.entities.undo', [$w->brand, $decision->id]))->assertOk();

    expect(EntityDecision::query()->count())->toBe(0)
        ->and(Competitor::query()->whereKey($competitor->id)->exists())->toBeFalse()
        ->and(Competitor::withTrashed()->whereKey($competitor->id)->first()->trashed())->toBeTrue()
        ->and(CompetitorAlias::query()->where('competitor_id', $competitor->id)->count())->toBe(0)
        ->and(Mention::query()->where('entity_type', 'competitor')->where('entity_id', $competitor->id)->count())->toBe(0)
        ->and(unknownMentions($w, 'Havnelys Studio') + unknownMentions($w, 'Havnelys Studio AS'))->toBe(6)
        ->and(aggregationSignals())->toBe(['2026-09-01', '2026-11-18'])
        ->and(array_keys(queueOf($w)))->toBe(['havnelys studio']);
});

test('competitor restores an archived competitor with the same key at the end of the color order', function () {
    $w = $this->w;
    $archived = Competitor::factory()->for($w->brand)->create(['name' => 'Havnelys Studio AS']);
    $archived->delete();
    namedAnswers($w, ['Havnelys Studio'], 2);

    decide($w, ['keys' => ['havnelys studio'], 'decision' => 'competitor'])->assertOk();

    $restored = Competitor::query()->whereKey($archived->id)->sole();
    expect($restored->is_active)->toBeTrue()
        ->and($restored->position)->toBeGreaterThan($w->c2->fresh()->position)
        ->and(Competitor::withTrashed()->where('brand_id', $w->brand->id)->count())->toBe(3)
        ->and(EntityDecision::query()->sole()->target_id)->toBe($archived->id);
});

test('a pitch keeps its limit of three competitors', function () {
    $w = $this->w;
    $w->tenant->forceFill(['kind' => 'pitch'])->save();
    Competitor::factory()->for($w->brand)->create(['name' => 'Tredje']);
    namedAnswers($w, ['Havnelys Studio'], 2);

    decide($w, ['keys' => ['havnelys studio'], 'decision' => 'competitor'])->assertStatus(422)->assertJsonValidationErrors('keys');

    expect(EntityDecision::query()->count())->toBe(0);
});

test('alias adds the spellings to the competitor and moves the mentions; undo removes only what it added', function () {
    $w = $this->w;
    CompetitorAlias::factory()->create(['competitor_id' => $w->c1->id, 'alias' => 'nordlys kaffe', 'match_mode' => 'case_insensitive']);
    namedAnswers($w, ['Nordlys Kaffe AS', 'NORDLYS KAFFE AS'], 4);

    decide($w, ['keys' => ['nordlys kaffe'], 'decision' => 'alias', 'target_type' => 'competitor', 'target_id' => $w->c1->id])
        ->assertStatus(422)->assertJsonValidationErrors('keys.0');  // the key is already known through the alias

    CompetitorAlias::query()->where('alias', 'nordlys kaffe')->delete();
    decide($w, ['keys' => ['nordlys kaffe'], 'decision' => 'alias', 'target_type' => 'competitor', 'target_id' => $w->c1->id])->assertOk();

    $decision = EntityDecision::query()->sole();
    expect($decision->decision)->toBe('alias')
        ->and($decision->created_competitor)->toBeFalse()
        // Spellings that differ only in case give one case-insensitive alias.
        ->and(CompetitorAlias::query()->whereIn('id', $decision->alias_ids)->pluck('alias')->map(fn ($a) => mb_strtolower($a))->all())->toBe(['nordlys kaffe as'])
        ->and(Mention::query()->where('entity_type', 'competitor')->where('entity_id', $w->c1->id)->count())->toBe(4);

    $kept = CompetitorAlias::factory()->create(['competitor_id' => $w->c1->id, 'alias' => 'Nordlys Norge', 'match_mode' => 'exact_word']);
    $this->actingAs($w->tenant->owner)->deleteJson(route('brands.entities.undo', [$w->brand, $decision->id]))->assertOk();

    expect(CompetitorAlias::query()->where('competitor_id', $w->c1->id)->pluck('id')->all())->toBe([$kept->id])
        ->and($w->c1->fresh()->trashed())->toBeFalse()
        ->and(Mention::query()->where('entity_type', 'unknown')->count())->toBe(4);
});

test('alias of the brand adds brand aliases, and needs brands.manage', function () {
    $w = $this->w;
    namedAnswers($w, ['Fjordkaffe Bergen'], 2);
    $payload = ['keys' => ['fjordkaffe bergen'], 'decision' => 'alias', 'target_type' => 'brand', 'target_id' => $w->brand->id];

    $editor = User::factory()->create();
    $w->tenant->users()->attach($editor, ['role' => 'editor']);
    $editor->switchTeam($w->tenant);
    $this->actingAs($editor)->postJson(route('brands.entities.decide', $w->brand), $payload)->assertForbidden();
    expect(EntityDecision::query()->count())->toBe(0);

    decide($w, $payload)->assertOk();

    expect(BrandAlias::query()->where('brand_id', $w->brand->id)->pluck('alias')->all())->toBe(['Fjordkaffe Bergen'])
        ->and(Mention::query()->where('entity_type', 'brand')->where('surface_name', 'Fjordkaffe Bergen')->count())->toBe(2);
});

test('other and ignored are only stored; the name leaves the queue and undo brings it back', function (string $decision) {
    $w = $this->w;
    namedAnswers($w, ['Shopify'], 2);
    $competitors = Competitor::withTrashed()->count();

    decide($w, ['keys' => ['shopify'], 'decision' => $decision])->assertOk();

    $record = EntityDecision::query()->sole();
    expect($record->decision)->toBe($decision)
        ->and($record->target_type)->toBeNull()
        ->and($record->alias_ids)->toBe([])
        ->and(Competitor::withTrashed()->count())->toBe($competitors)
        ->and(unknownMentions($w, 'Shopify'))->toBe(2)
        ->and(queueOf($w))->toBe([]);

    decide($w, ['keys' => ['shopify'], 'decision' => 'competitor'])->assertStatus(422)->assertJsonValidationErrors('keys.0');

    $this->actingAs($w->tenant->owner)->deleteJson(route('brands.entities.undo', [$w->brand, $record->id]))->assertOk();
    expect(array_keys(queueOf($w)))->toBe(['shopify']);
})->with(['other', 'ignored']);

test('a decision cannot be undone after 24 hours', function () {
    $w = $this->w;
    namedAnswers($w, ['Havnelys Studio'], 2);
    decide($w, ['keys' => ['havnelys studio'], 'decision' => 'competitor'])->assertOk();
    $decision = EntityDecision::query()->sole();

    $this->travel(25)->hours();
    $this->actingAs($w->tenant->owner)->deleteJson(route('brands.entities.undo', [$w->brand, $decision->id]))
        ->assertStatus(422)->assertJsonPath('code', 'undo_expired');

    expect(EntityDecision::query()->count())->toBe(1)
        ->and(Competitor::query()->where('name', 'Havnelys Studio')->exists())->toBeTrue();
});

test('unknown keys and wrong targets are refused before anything is written', function () {
    $w = $this->w;
    namedAnswers($w, ['Havnelys Studio'], 2);

    decide($w, ['keys' => ['havnelys studio', 'finnes ikke'], 'decision' => 'other'])->assertStatus(422)->assertJsonValidationErrors('keys.1');
    decide($w, ['keys' => ['havnelys studio'], 'decision' => 'alias'])->assertStatus(422)->assertJsonValidationErrors(['target_type', 'target_id']);
    decide($w, ['keys' => ['havnelys studio'], 'decision' => 'alias', 'target_type' => 'competitor', 'target_id' => 999999])->assertStatus(422)->assertJsonValidationErrors('target_id');
    decide($w, ['keys' => [], 'decision' => 'other'])->assertStatus(422);
    decide($w, ['keys' => array_fill(0, 101, 'x'), 'decision' => 'other'])->assertStatus(422);

    expect(EntityDecision::query()->count())->toBe(0);
});

test('excerpts show the name with 80 characters on each side from the three newest answers', function () {
    $w = $this->w;
    $text = str_repeat('a', 100).'Havnelys Studio'.str_repeat('b', 100);
    foreach (['2026-11-15', '2026-11-16', '2026-11-17', '2026-11-18'] as $day) {
        $response = $w->answer($w->p1, "{$day} 10:00:00", ['unknown' => 'Havnelys Studio']);
        $response->forceFill(['answer_text' => $text, 'raw_uri' => 'gs://raw/secret.json'])->save();
    }

    $json = $this->actingAs($w->tenant->owner)->getJson(route('brands.entities.excerpts', [$w->brand, 'havnelys studio']))->assertOk()->json();

    expect($json['excerpts'])->toHaveCount(3)
        ->and($json['excerpts'][0]['collected_at'])->toStartWith('2026-11-18')
        ->and($json['excerpts'][0]['before'])->toBe('…'.str_repeat('a', 80))
        ->and($json['excerpts'][0]['match'])->toBe('Havnelys Studio')
        ->and($json['excerpts'][0]['after'])->toBe(str_repeat('b', 80).'…')
        ->and($json['excerpts'][0]['market']['id'])->toBe($w->market->id)
        ->and(json_encode($json))->not->toContain('gs://');
});

test('a decision in one tenant does not touch another tenant with the same name', function () {
    $w = $this->w;
    namedAnswers($w, ['Havnelys Studio'], 2);
    $other = MetricsWorld::create();
    namedAnswers($other, ['Havnelys Studio'], 2);
    app(TenantContext::class)->set($w->tenant->id);

    decide($w, ['keys' => ['havnelys studio'], 'decision' => 'competitor'])->assertOk();

    app(TenantContext::class)->run($other->tenant->id, function () use ($other): void {
        expect(EntityDecision::query()->count())->toBe(0)
            ->and(Competitor::query()->where('name', 'Havnelys Studio')->exists())->toBeFalse()
            ->and(unknownMentions($other, 'Havnelys Studio'))->toBe(2)
            ->and(array_keys(queueOf($other)))->toBe(['havnelys studio']);
    });
    expect(Team::query()->whereKey($other->tenant->id)->exists())->toBeTrue();
});
