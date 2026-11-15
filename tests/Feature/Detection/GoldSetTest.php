<?php

// Gold set for the deterministic stage (docs/spec.md 8.1, chapter 18): synthetic answers in nb, nn and sv
// with the expected mentions, list positions and citations. The stage must find 100 % of the known aliases
// and nothing else.

use App\Detection\DetectionCatalog;
use App\Detection\DeterministicDetector;
use App\Models\Brand;
use App\Models\Citation;
use App\Models\Competitor;
use App\Models\Mention;
use App\Models\Response;
use App\Models\Team;
use App\Tenancy\TenantContext;

/** @return array{catalog: array<string, mixed>, responses: list<array<string, mixed>>} */
function goldSet(): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/extraction/gold.json'), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Creates the gold catalog in the tenant in the context.
 *
 * @return array{brands: array<string, int>, competitors: array<string, int>}
 */
function createGoldCatalog(): array
{
    $ids = ['brands' => [], 'competitors' => []];
    $catalog = goldSet()['catalog'];

    foreach ($catalog['brands'] as $row) {
        $brand = Brand::factory()->create(['name' => $row['name'], 'slug' => $row['key'], 'primary_domain' => $row['domains'][0]['domain']]);
        foreach ($row['aliases'] as $alias) {
            $brand->aliases()->create(['alias' => $alias['alias'], 'match_mode' => $alias['mode']]);
        }
        foreach ($row['domains'] as $domain) {
            $brand->domains()->create(['domain' => $domain['domain'], 'include_subdomains' => $domain['include_subdomains']]);
        }
        $ids['brands'][$row['key']] = $brand->id;
    }

    $owner = Brand::query()->where('slug', $catalog['brands'][0]['key'])->sole();
    foreach ($catalog['competitors'] as $row) {
        $competitor = Competitor::factory()->for($owner)->create(['name' => $row['key']]);
        foreach ($row['aliases'] as $alias) {
            $competitor->aliases()->create(['alias' => $alias['alias'], 'match_mode' => $alias['mode']]);
        }
        foreach ($row['domains'] as $domain) {
            $competitor->domains()->create(['domain' => $domain]);
        }
        $ids['competitors'][$row['key']] = $competitor->id;
    }

    return $ids;
}

/**
 * @param  array{brands: array<string, int>, competitors: array<string, int>}  $ids
 * @return array{type: string, id: int}
 */
function goldEntity(array $ids, string $key): array
{
    [$type, $name] = explode(':', $key, 2);

    return ['type' => $type, 'id' => $ids[$type === 'brand' ? 'brands' : 'competitors'][$name]];
}

/** @return array<string, array{0: array<string, mixed>}> */
function goldResponses(): array
{
    $datasets = [];
    foreach (goldSet()['responses'] as $response) {
        $datasets[$response['id']] = [$response];
    }

    return $datasets;
}

beforeEach(function () {
    $this->tenant = Team::factory()->tenant()->create();
    app(TenantContext::class)->set($this->tenant->id);
    $this->ids = createGoldCatalog();
});

it('has a gold set of at least 40 answers in nb, nn and sv', function () {
    $responses = goldSet()['responses'];
    $byLanguage = array_count_values(array_column($responses, 'language'));

    expect(count($responses))->toBeGreaterThanOrEqual(40)
        ->and($byLanguage)->toHaveKeys(['nb', 'nn', 'sv'])
        ->and(($byLanguage['nb'] ?? 0) + ($byLanguage['sv'] ?? 0))->toBeGreaterThanOrEqual(40);
});

it('finds every known alias in the gold set and nothing else (100 % recall, no false positives)', function () {
    $detector = new DeterministicDetector;
    $catalog = DetectionCatalog::load();
    $expectedTotal = 0;
    $found = 0;
    $extra = [];
    $missed = [];

    foreach (goldSet()['responses'] as $gold) {
        $response = Response::factory()->create(['answer_text' => $gold['text'], 'answer_language' => $gold['language']]);
        $detector->detect($response, $catalog);
        $actual = Mention::query()->where('response_id', $response->id)->get()
            ->map(fn (Mention $m): string => $m->entity_type.':'.$m->entity_id)->all();

        foreach ($gold['expected'] as $expected) {
            $entity = goldEntity($this->ids, $expected['entity']);
            $expectedTotal++;
            if (in_array($entity['type'].':'.$entity['id'], $actual, true)) {
                $found++;
            } else {
                $missed[] = $gold['id'].' '.$expected['entity'];
            }
        }

        $wanted = array_map(fn (array $e): string => implode(':', goldEntity($this->ids, $e['entity'])), $gold['expected']);
        foreach (array_diff($actual, $wanted) as $unexpected) {
            $extra[] = $gold['id'].' '.$unexpected;
        }
    }

    expect($expectedTotal)->toBeGreaterThan(50)
        ->and($missed)->toBe([])
        ->and($found)->toBe($expectedTotal)
        ->and($extra)->toBe([]);
});

it('matches the expected mention, position, offset and citations for each gold answer', function (array $gold) {
    $response = Response::factory()->create(['answer_text' => $gold['text'], 'answer_language' => $gold['language']]);
    foreach ($gold['citations'] as $citation) {
        Citation::query()->create([
            'response_id' => $response->id,
            'url' => $citation['url'],
            'url_normalized' => $citation['url'],
            'domain' => 'placeholder',
            'ownership' => 'third_party',
            'from_annotation' => true,
        ]);
    }

    (new DeterministicDetector)->detect($response);

    $mentions = Mention::query()->where('response_id', $response->id)->orderBy('first_char_offset')->get();
    $expected = array_map(fn (array $e): array => ['key' => implode(':', goldEntity($this->ids, $e['entity'])), 'surface' => $e['surface'], 'position' => $e['list_position']], $gold['expected']);
    $actual = $mentions->map(fn (Mention $m): array => ['key' => $m->entity_type.':'.$m->entity_id, 'surface' => $m->surface_name, 'position' => $m->list_position])->all();

    expect($actual)->toBe($expected);

    foreach ($mentions as $mention) {
        expect($mention->detection)->toBe('deterministic')
            ->and($mention->is_recommended)->toBeFalse()
            ->and($mention->sentiment)->toBeNull()
            // first_char_offset is a code point offset into the original answer text.
            ->and(mb_substr($gold['text'], $mention->first_char_offset, mb_strlen($mention->surface_name)))->toBe($mention->surface_name);
    }

    $citations = Citation::query()->where('response_id', $response->id)->orderBy('id')->get();
    expect($citations)->toHaveCount(count($gold['citations']));
    foreach ($gold['citations'] as $i => $expectedCitation) {
        expect($citations[$i]->url_normalized)->toBe($expectedCitation['normalized'])
            ->and($citations[$i]->ownership)->toBe($expectedCitation['ownership'])
            ->and($citations[$i]->competitor_id)->toBe($expectedCitation['competitor'] === null ? null : $this->ids['competitors'][$expectedCitation['competitor']]);
    }
})->with(goldResponses());
