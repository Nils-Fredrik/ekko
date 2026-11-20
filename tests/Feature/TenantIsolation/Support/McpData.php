<?php

namespace Tests\Feature\TenantIsolation\Support;

use App\Models\Action;
use App\Models\AggDaily;
use App\Models\AggSourceDaily;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\BotHitDaily;
use App\Models\Brand;
use App\Models\BrandFact;
use App\Models\Citation;
use App\Models\Competitor;
use App\Models\Engine;
use App\Models\Market;
use App\Models\Mention;
use App\Models\Prompt;
use App\Models\Report;
use App\Models\Response;
use App\Models\Run;
use App\Models\RunBatch;
use App\Models\Team;
use App\Models\Topic;
use App\Models\UsageEvent;
use App\Tenancy\TenantContext;

/**
 * Data in a tenant for the MCP isolation tests: every text carries the label, so a leak from tenant B shows up
 * as B's marker in a tool answer. Returns the ids the tool register uses.
 */
final class McpData
{
    /**
     * @return array{brand: int, market: int, prompt: int, topic: int, action: int, report: int}
     */
    public static function seed(Team $tenant, string $label): array
    {
        return app(TenantContext::class)->run($tenant->id, function () use ($label): array {
            $engine = Engine::query()->find('isolation_engine') ?? Engine::factory()->create(['key' => 'isolation_engine']);
            $day = now('Europe/Oslo')->format('Y-m-d');
            $brand = Brand::factory()->create(['name' => "{$label} brand", 'primary_domain' => strtolower($label).'.example.test']);
            $market = Market::factory()->for($brand)->create(['location_label' => "{$label} city"]);
            $topic = Topic::factory()->create(['brand_id' => $brand->id, 'name' => "{$label} topic"]);
            $competitor = Competitor::factory()->for($brand)->create(['name' => "{$label} competitor"]);
            $prompt = Prompt::factory()->for($market)->create(['text' => "{$label} prompt text", 'topic_id' => $topic->id]);

            $batch = RunBatch::factory()->create(['market_id' => $market->id]);
            $run = Run::factory()->create(['run_batch_id' => $batch->id, 'prompt_id' => $prompt->id, 'engine_key' => $engine->key, 'status' => 'extracted']);
            $response = Response::factory()->create(['run_id' => $run->id, 'engine_key' => $engine->key, 'collected_at' => now()->subHour(), 'answer_text' => "{$label} answer text"]);
            Mention::factory()->create(['response_id' => $response->id, 'entity_type' => 'competitor', 'entity_id' => $competitor->id, 'surface_name' => "{$label} competitor", 'detection' => 'deterministic']);
            Citation::factory()->create(['response_id' => $response->id, 'url' => 'https://'.strtolower($label).'.example.test/x', 'url_normalized' => 'https://'.strtolower($label).'.example.test/x', 'domain' => strtolower($label).'.example.test', 'ownership' => 'third_party']);

            AggDaily::factory()->create([
                'brand_id' => $brand->id, 'market_id' => $market->id, 'engine_key' => $engine->key, 'prompt_id' => $prompt->id, 'date' => $day,
                'responses' => 12, 'brand_mentioned' => 5, 'competitor_mentions' => [(string) $competitor->id => 7],
            ]);
            AggSourceDaily::factory()->create([
                'brand_id' => $brand->id, 'market_id' => $market->id, 'engine_key' => $engine->key, 'date' => $day,
                'domain' => strtolower($label).'.example.test', 'citations' => 2, 'responses_with_domain' => 2, 'gap_responses' => 1,
            ]);
            BotHitDaily::factory()->create(['brand_id' => $brand->id, 'date' => $day, 'bot' => "{$label}Bot", 'hits' => 3]);
            $rule = AlertRule::factory()->create(['brand_id' => $brand->id]);
            Alert::factory()->create(['alert_rule_id' => $rule->id, 'acknowledged_by' => null, 'payload' => ['note' => "{$label} alert"]]);
            UsageEvent::factory()->create(['occurred_at' => now(), 'kind' => 'engine_call', 'engine_key' => $engine->key, 'calls' => 1]);

            $action = Action::factory()->create(['brand_id' => $brand->id, 'title' => "{$label} action", 'status' => 'planned']);
            $report = Report::factory()->create(['web_snapshot' => ['brand' => "{$label} report"]]);
            BrandFact::factory()->create(['brand_id' => $brand->id, 'text' => "{$label} fact"]);

            return ['action' => $action->id, 'report' => $report->id, 'brand' => $brand->id, 'market' => $market->id, 'prompt' => $prompt->id, 'topic' => $topic->id];
        });
    }

    /**
     * A fingerprint of the prompts in a tenant, to prove a call changed nothing.
     *
     * @return list<array<string, mixed>>
     */
    public static function prompts(Team $tenant): array
    {
        return Prompt::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('id')
            ->get(['id', 'market_id', 'topic_id', 'text', 'intent', 'funnel_stage', 'priority', 'is_active'])->toArray();
    }
}
