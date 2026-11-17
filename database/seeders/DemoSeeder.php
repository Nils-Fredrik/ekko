<?php

namespace Database\Seeders;

use App\Models\AggDaily;
use App\Models\AggSourceDaily;
use App\Models\AiReferralDaily;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\BotHitDaily;
use App\Models\Brand;
use App\Models\BrandAlias;
use App\Models\BrandDomain;
use App\Models\Citation;
use App\Models\Competitor;
use App\Models\CompetitorAlias;
use App\Models\CompetitorDomain;
use App\Models\Engine;
use App\Models\EnginePrice;
use App\Models\ExtractionJob;
use App\Models\Integration;
use App\Models\Market;
use App\Models\MarketEngine;
use App\Models\Mention;
use App\Models\Plan;
use App\Models\Prompt;
use App\Models\Report;
use App\Models\ReportSchedule;
use App\Models\Response;
use App\Models\Run;
use App\Models\RunBatch;
use App\Models\Team;
use App\Models\Topic;
use App\Models\UsageEvent;
use App\Models\UsageMonthly;
use App\Models\User;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    private const ENGINES = [
        'chatgpt_ui' => ['chatgpt', 'ui_scrape', 'dataforseo', null, 'off', 'all'],
        'gemini_ui' => ['gemini', 'ui_scrape', 'dataforseo', null, 'off', 'all'],
        'google_aio' => ['google_aio', 'serp', 'dataforseo', null, 'off', 'all'],
        'google_ai_mode' => ['google_aio', 'serp', 'dataforseo', null, 'off', 'all'],
        'claude_haiku' => ['claude', 'api', 'anthropic', 'claude-haiku-5-5', 'daily', 'all'],
        'claude_sonnet' => ['claude', 'api', 'anthropic', 'claude-sonnet-5-5', 'weekly', 'core'],
        'perplexity_api' => ['perplexity', 'api', 'perplexity', null, 'off', 'all'],
        'chatgpt_api' => ['chatgpt', 'api', 'openai', null, 'daily', 'all'],
        'gemini_api' => ['gemini', 'api', 'google', null, 'daily', 'all'],
    ];

    public function run(TenantContext $context, TenantRls $rls): void
    {
        DB::transaction(function () use ($context, $rls): void {
            $this->seedEngines();
            $plan = Plan::query()->firstOrCreate(['name' => 'Demo'], [
                'max_brands' => 3,
                'max_markets_per_brand' => 2,
                'max_prompts_per_market' => 50,
                'allowed_engines' => ['chatgpt_api', 'gemini_api', 'claude_haiku', 'claude_sonnet'],
                'max_frequency' => 'daily',
                'max_repetitions' => 3,
                'monthly_call_quota' => 50000,
                'monthly_budget_usd' => '300.00',
            ]);

            $partner = $this->seedTeam('Demo Partner', 'partner@example.test', $plan);
            $norway = $this->seedTeam('Demo Norge', 'norway@example.test', $plan, $partner);
            $sweden = $this->seedTeam('Demo Sverige', 'sweden@example.test', $plan, $partner);

            foreach ([
                [$partner->id, 'Nordlys Studio', 'NO', 'nb', 'Oslo'],
                [$norway->id, 'Fjord Studio', 'NO', 'nb', 'Bergen'],
                [$sweden->id, 'Skärgård Studio', 'SE', 'sv', 'Göteborg'],
            ] as [$tenantId, $name, $country, $language, $location]) {
                $context->run($tenantId, fn () => $rls->run([$tenantId],
                    fn () => $this->seedTenant($name, $country, $language, $location)));
            }
        });
    }

    private function seedEngines(): void
    {
        foreach (self::ENGINES as $key => [$family, $method, $provider, $model]) {
            Engine::query()->firstOrCreate(['key' => $key], [
                'family' => $family,
                'method' => $method,
                'provider' => $provider,
                'is_enabled' => ! in_array($provider, ['dataforseo', 'perplexity'], true),
                'model_id' => $model,
            ]);

            EnginePrice::query()->firstOrCreate([
                'engine_key' => $key,
                'valid_from' => '2026-01-01T00:00:00Z',
            ], [
                'price_per_call_usd' => '0.00100000',
            ]);
        }
    }

    private function seedTeam(string $name, string $email, Plan $plan, ?Team $partner = null): Team
    {
        $user = User::query()->where('email', $email)->first()
            ?? User::factory()->create(['name' => $name, 'email' => $email]);

        $team = Team::query()->whereBelongsTo($user, 'owner')->where('name', $name)->first()
            ?? Team::factory()->tenant()->for($user, 'owner')->withPlan($plan)->create([
                'name' => $name,
                'partner_team_id' => $partner?->id,
            ]);

        if ($user->current_team_id === null) {
            $user->switchTeam($team);
        }

        return $team;
    }

    private function seedTenant(string $name, string $country, string $language, string $location): void
    {
        $slug = Str::slug($name);
        if (Brand::query()->where('slug', $slug)->exists()) {
            return;
        }

        $brand = Brand::factory()->create([
            'name' => $name,
            'slug' => $slug,
            'primary_domain' => $slug.'.example.test',
        ]);
        BrandAlias::factory()->for($brand)->create(['alias' => $name]);
        BrandDomain::factory()->for($brand)->create([
            'domain' => $brand->primary_domain,
            'include_subdomains' => true,
        ]);

        $competitor = Competitor::factory()->for($brand)->create(['name' => 'Gran Studio']);
        CompetitorAlias::factory()->for($competitor)->create(['alias' => $competitor->name]);
        CompetitorDomain::factory()->for($competitor)->create(['domain' => 'gran-studio.example.test']);

        $market = Market::factory()->for($brand)->create([
            'country' => $country,
            'language' => $language,
            'location_label' => $location,
        ]);
        foreach (self::ENGINES as $key => [, , , , $frequency, $scope]) {
            MarketEngine::factory()->for($market)->create([
                'engine_key' => $key,
                'frequency' => $frequency,
                'prompt_scope' => $scope,
            ]);
        }

        $topic = Topic::factory()->for($brand)->create([
            'name' => $language === 'sv' ? 'Varumärkesdesign' : 'Merkevarebygging',
            'cross_market_key' => 'brand-design',
        ]);
        $prompt = Prompt::factory()->for($market)->for($topic)->create([
            'text' => $language === 'sv'
                ? "Vilka designstudior i {$location} arbetar med varumärken?"
                : "Hvilke designstudioer i {$location} jobber med merkevarer?",
            'priority' => 'core',
        ]);
        Prompt::factory()->for($market)->for($topic)->create([
            'text' => $language === 'sv'
                ? "Vad kostar en visuell identitet i {$location}?"
                : "Hva koster en visuell identitet i {$location}?",
            'intent' => 'comparison',
            'funnel_stage' => 'consideration',
        ]);

        $this->seedResults($brand, $competitor, $market, $prompt);
        $this->seedOperations($brand, $language);
    }

    private function seedResults(Brand $brand, Competitor $competitor, Market $market, Prompt $prompt): void
    {
        for ($daysAgo = 14; $daysAgo > 0; $daysAgo--) {
            $scheduledFor = today()->subDays($daysAgo)->addHour();
            $finishedAt = $scheduledFor->copy()->addMinute();
            $batch = RunBatch::factory()->for($market)->create([
                'scheduled_for' => $scheduledFor,
                'status' => 'completed',
                'completed_runs' => 1,
            ]);
            $run = Run::factory()->for($batch)->for($prompt)->create([
                'engine_key' => 'chatgpt_ui',
                'status' => 'extracted',
                'attempts' => 1,
                'started_at' => $scheduledFor,
                'finished_at' => $finishedAt,
            ]);
            $isMentioned = $daysAgo % 3 !== 0;
            $answer = $isMentioned
                ? "1. {$brand->name}\n2. {$competitor->name}"
                : "1. {$competitor->name}";
            $response = Response::factory()->for($run)->create([
                'answer_language' => $market->language,
                'answer_text' => $answer,
                'collected_at' => $finishedAt,
            ]);

            Mention::factory()->for($response)->create([
                'entity_type' => 'competitor',
                'entity_id' => $competitor->id,
                'surface_name' => $competitor->name,
                'first_char_offset' => mb_strpos($answer, $competitor->name),
                'list_position' => $isMentioned ? 2 : 1,
                'is_recommended' => ! $isMentioned,
                'detection' => 'deterministic',
            ]);
            if ($isMentioned) {
                Mention::factory()->for($response)->create([
                    'entity_type' => 'brand',
                    'entity_id' => $brand->id,
                    'surface_name' => $brand->name,
                    'first_char_offset' => 3,
                    'list_position' => 1,
                    'is_recommended' => true,
                    'sentiment' => '0.750',
                    'detection' => 'deterministic',
                ]);
            }

            $domain = $isMentioned ? $brand->primary_domain : 'directory.example.test';
            $url = 'https://'.$domain.'/guide';
            Citation::factory()->for($response)->create([
                'url' => $url,
                'url_normalized' => $url,
                'domain' => $domain,
                'ownership' => $isMentioned ? 'own' : 'third_party',
            ]);
            ExtractionJob::factory()->create([
                'status' => 'completed',
                'response_ids' => [$response->id],
                'submitted_at' => $response->collected_at,
                'completed_at' => $finishedAt->copy()->addMinutes(5),
            ]);

            AggDaily::factory()->for($brand)->for($market)->for($prompt)->create([
                'engine_key' => $run->engine_key,
                'date' => $scheduledFor,
                'brand_mentioned' => (int) $isMentioned,
                'brand_recommended' => (int) $isMentioned,
                'sum_position' => (int) $isMentioned,
                'count_position' => (int) $isMentioned,
                'sum_sentiment' => $isMentioned ? '0.750' : '0.000',
                'own_cited' => (int) $isMentioned,
                'competitor_mentions' => [$competitor->id => 1],
            ]);
            AggSourceDaily::factory()->for($brand)->for($market)->create([
                'engine_key' => $run->engine_key,
                'domain' => $domain,
                'date' => $scheduledFor,
            ]);
            BotHitDaily::factory()->for($brand)->create(['date' => $scheduledFor]);
            AiReferralDaily::factory()->for($brand)->create(['date' => $scheduledFor]);
            UsageEvent::factory()->for($run)->create(['occurred_at' => $response->collected_at]);
        }
    }

    private function seedOperations(Brand $brand, string $language): void
    {
        $months = UsageEvent::query()
            ->selectRaw("date_trunc('month', occurred_at AT TIME ZONE 'UTC')::date AS month, sum(calls) AS calls, sum(cost_usd) AS cost_usd")
            ->groupBy('month')
            ->toBase()
            ->get();

        foreach ($months as $month) {
            UsageMonthly::factory()->create([
                'month' => $month->month,
                'calls' => $month->calls,
                'cost_usd' => $month->cost_usd,
                'quota_calls' => 50000,
                'budget_usd' => '300.00',
            ]);
        }

        $alertRule = AlertRule::factory()->for($brand)->create();
        Alert::factory()->for($alertRule)->create();
        $schedule = ReportSchedule::factory()->for($brand)->create(['locale' => $language]);
        Report::factory()->for($schedule)->create();
        Integration::factory()->for($brand)->create(['status' => 'disabled']);

        activity()->performedOn($brand)->event('created')->log('demo_brand_created');
    }
}
