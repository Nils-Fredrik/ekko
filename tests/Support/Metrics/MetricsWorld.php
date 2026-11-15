<?php

namespace Tests\Support\Metrics;

use App\Models\Brand;
use App\Models\Citation;
use App\Models\Competitor;
use App\Models\Engine;
use App\Models\Market;
use App\Models\Mention;
use App\Models\Prompt;
use App\Models\Response;
use App\Models\Run;
use App\Models\RunBatch;
use App\Models\Team;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * A tenant with one brand, two active competitors, a market, an engine and two prompts, set as the tenant in the
 * context, and a way to add extracted answers with known mentions and citations (T21 tests).
 */
final class MetricsWorld
{
    public Team $tenant;

    public Brand $brand;

    public Market $market;

    public Competitor $c1;

    public Competitor $c2;

    public Engine $engine;

    public Prompt $p1;

    public Prompt $p2;

    public static function create(string $timezone = 'Europe/Oslo'): self
    {
        $world = new self;
        $world->tenant = Team::factory()->tenant()->create();
        DB::table('teams')->where('id', $world->tenant->id)->update(['timezone' => $timezone]);
        app(TenantContext::class)->set($world->tenant->id);

        $world->brand = Brand::factory()->create(['name' => 'Fjordkaffe']);
        $world->market = Market::factory()->for($world->brand)->create();
        $world->c1 = Competitor::factory()->for($world->brand)->create(['name' => 'Nordlys']);
        $world->c2 = Competitor::factory()->for($world->brand)->create(['name' => 'Solbaer']);
        $world->engine = Engine::factory()->create();
        $world->p1 = Prompt::factory()->for($world->market)->create();
        $world->p2 = Prompt::factory()->for($world->market)->create();

        return $world;
    }

    /**
     * One answer collected at the given UTC time. Options: engine, status (run status, default extracted), type
     * (answer_type), model (model_id_reported), brand (mention: pos, rec, sent), competitors (id => pos|null),
     * unknown (bool), otherBrand (a brand id mentioned too), citations (list of [domain, ownership]).
     *
     * @param  array<string, mixed>  $options
     */
    public function answer(Prompt $prompt, string $collectedAt, array $options = []): Response
    {
        $engine = $options['engine'] ?? $this->engine;
        $batch = RunBatch::factory()->create(['market_id' => $prompt->market_id]);
        $run = Run::factory()->create([
            'run_batch_id' => $batch->id, 'prompt_id' => $prompt->id, 'engine_key' => $engine->key, 'status' => $options['status'] ?? 'extracted',
        ]);
        $response = Response::factory()->create([
            'run_id' => $run->id, 'engine_key' => $engine->key, 'collected_at' => $collectedAt,
            'answer_type' => $options['type'] ?? null, 'model_id_reported' => $options['model'] ?? null,
        ]);

        if (isset($options['brand'])) {
            $b = $options['brand'];
            Mention::factory()->create([
                'response_id' => $response->id, 'entity_type' => 'brand', 'entity_id' => $this->brand->id, 'detection' => 'deterministic',
                'list_position' => $b['pos'] ?? null, 'is_recommended' => $b['rec'] ?? false, 'sentiment' => $b['sent'] ?? null,
            ]);
        }
        foreach ($options['competitors'] ?? [] as $competitorId => $position) {
            Mention::factory()->create([
                'response_id' => $response->id, 'entity_type' => 'competitor', 'entity_id' => $competitorId,
                'detection' => 'deterministic', 'list_position' => $position,
            ]);
        }
        if (isset($options['otherBrand'])) {
            Mention::factory()->create([
                'response_id' => $response->id, 'entity_type' => 'brand', 'entity_id' => $options['otherBrand'],
                'detection' => 'deterministic', 'list_position' => 1,
            ]);
        }
        if (! empty($options['unknown'])) {
            Mention::factory()->create(['response_id' => $response->id, 'entity_type' => 'unknown', 'detection' => 'llm', 'list_position' => 1, 'sentiment' => 0.9]);
        }
        foreach ($options['citations'] ?? [] as [$domain, $ownership]) {
            Citation::factory()->create(['response_id' => $response->id, 'domain' => $domain, 'url' => "https://{$domain}/x", 'url_normalized' => "https://{$domain}/x", 'ownership' => $ownership]);
        }

        return $response;
    }
}
