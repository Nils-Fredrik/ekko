<?php

use App\MeasurementPlan\StandardPlan;
use App\Models\Brand;
use App\Models\Engine;
use App\Models\Market;
use App\Models\MarketEngine;
use App\Models\Plan;
use App\Models\Prompt;
use App\Models\Run;
use App\Models\RunBatch;
use App\Models\Team;
use App\Scheduling\Dispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../Support/MeasurementPlan/helpers.php';

// c22 (task:124, docs/spec.md 7.6): the migration keeps the actual run pattern of every existing market, also off
// engines, separate core rules and markets without engines, and gives every brand the standard plan of its package.

beforeEach(function () {
    Http::fake();
    $this->migration = require database_path('migrations/2026_11_21_100000_create_brand_measurement_plans.php');
    $this->travelTo(CarbonImmutable::parse('2026-11-20T12:00:00Z'));
});

/** @return list<string> */
function migrationRuns(array $tenants): array
{
    $runs = [];
    foreach ($tenants as $tenant) {
        foreach (inTenant($tenant, fn () => Run::query()->with('runBatch')->get()) as $run) {
            $runs[] = $tenant->id.':'.$run->runBatch->market_id.':'.$run->prompt_id.':'.$run->engine_key.':'.$run->repetition;
        }
    }
    sort($runs);

    return $runs;
}

it('keeps every existing market\'s rules and run pattern and gives each brand its package standard', function () {
    planCatalogue();
    Engine::query()->whereKey('gemini_api')->update(['is_enabled' => false]);
    $this->migration->down();

    // Production-like data before T53: a capped package, a tenant without a package, separate core rules, off
    // engines, a market without engines, an inactive market and a brand without markets.
    $capped = Plan::factory()->create([
        'allowed_engines' => ['chatgpt_api', 'gemini_api', 'claude_haiku', 'claude_sonnet'],
        'max_frequency' => 'weekly', 'engine_max_frequencies' => ['claude_haiku' => 'monthly'], 'max_repetitions' => 3,
        'monthly_call_quota' => 100000,
    ]);
    $packaged = Team::factory()->tenant()->withPlan($capped)->create(['timezone' => 'UTC']);
    $unpackaged = Team::factory()->tenant()->create(['timezone' => 'UTC', 'plan_id' => null]);
    $world = inTenant($packaged, function (): array {
        $brand = Brand::factory()->create(['status' => 'active']);
        $split = Market::factory()->for($brand)->create(['country' => 'NO', 'language' => 'nb']);
        MarketEngine::factory()->for($split)->create(planRule('chatgpt_api', 'weekly', 'all', 2));
        MarketEngine::factory()->for($split)->create(planRule('chatgpt_api', 'daily', 'core'));
        MarketEngine::factory()->for($split)->create(planRule('claude_haiku', 'off'));
        MarketEngine::factory()->for($split)->create(planRule('claude_sonnet', 'weekly', 'core', 3));
        $empty = Market::factory()->for($brand)->create(['country' => 'SE', 'language' => 'sv']);
        $inactive = Market::factory()->for($brand)->create(['country' => 'DK', 'language' => 'da', 'is_active' => false]);
        MarketEngine::factory()->for($inactive)->create(planRule('chatgpt_api', 'daily'));
        foreach ([$split, $empty, $inactive] as $market) {
            Prompt::factory()->for($market)->count(4)->create(['is_active' => true, 'priority' => 'standard']);
            Prompt::factory()->for($market)->count(2)->create(['is_active' => true, 'priority' => 'core']);
        }
        $bare = Brand::factory()->create(['status' => 'active']);

        return ['brand' => $brand, 'bare' => $bare];
    });
    $orphan = inTenant($unpackaged, function (): Brand {
        $brand = Brand::factory()->create(['status' => 'active']);
        $market = Market::factory()->for($brand)->create();
        MarketEngine::factory()->for($market)->create(planRule('chatgpt_api', 'daily'));

        return $brand;
    });
    $snapshot = fn () => inTenant($packaged, fn () => DB::table('market_engines')->orderBy('id')->get()->toArray())
        + ['other' => inTenant($unpackaged, fn () => DB::table('market_engines')->orderBy('id')->get()->toArray())];
    $engines = $snapshot();

    // The actual run pattern over a week before the migration.
    for ($day = 0; $day < 7; $day++) {
        app(Dispatcher::class)->dispatch(CarbonImmutable::now('UTC')->addDays($day));
    }
    $before = migrationRuns([$packaged, $unpackaged]);
    foreach ([$packaged, $unpackaged] as $tenant) {
        inTenant($tenant, function (): void {
            Run::query()->delete();
            RunBatch::query()->delete();
        });
    }

    $this->migration->up();

    for ($day = 0; $day < 7; $day++) {
        app(Dispatcher::class)->dispatch(CarbonImmutable::now('UTC')->addDays($day));
    }
    expect($before)->not->toBe([])
        ->and(migrationRuns([$packaged, $unpackaged]))->toBe($before)
        ->and($snapshot())->toEqual($engines);

    $standard = [
        planRule('chatgpt_api', 'weekly'),
        planRule('claude_haiku', 'monthly'),
        planRule('claude_sonnet', 'weekly', 'core'),
        planRule('gemini_api', 'off'),
    ];
    inTenant($packaged, function () use ($world, $standard): void {
        expect(Market::query()->pluck('plan_mode')->unique()->all())->toBe([Market::PLAN_OWN])
            ->and(storedStandard($world['brand']))->toBe(sortedRules($standard))
            ->and(storedStandard($world['bare']))->toBe(sortedRules($standard));
    });
    inTenant($unpackaged, function () use ($orphan): void {
        expect(Market::query()->sole()->plan_mode)->toBe(Market::PLAN_OWN)
            ->and(array_unique(array_column(storedStandard($orphan), 'frequency')))->toBe(['off'])
            ->and(count(storedStandard($orphan)))->toBe(4);
    });
});

it('gives a market inserted without a plan mode own choices and refuses an unknown mode', function () {
    planCatalogue(['chatgpt_api']);
    [$tenant] = planTenant();
    inTenant($tenant, function (): void {
        // A row inserted without plan_mode, as code from before T53 would during a rollout.
        $brand = Brand::factory()->create();
        $id = DB::table('markets')->insertGetId(['tenant_id' => $brand->tenant_id, 'brand_id' => $brand->id, 'country' => 'NO', 'language' => 'nb', 'is_active' => true]);
        expect(Market::query()->findOrFail($id)->plan_mode)->toBe(Market::PLAN_OWN);
        expect(fn () => DB::transaction(fn () => DB::table('markets')->where('id', $id)->update(['plan_mode' => 'borrowed'])))
            ->toThrow(QueryException::class);
    });
});

it('starts an engine without a current price off in the standard plan', function () {
    planCatalogue(['chatgpt_api', 'claude_haiku']);
    DB::table('engine_prices')->where('engine_key', 'claude_haiku')->delete();
    [$tenant] = planTenant();

    $rules = collect(StandardPlan::forTenant($tenant))->keyBy('engine_key');
    expect($rules['chatgpt_api']['frequency'])->toBe('daily')
        ->and($rules['claude_haiku']['frequency'])->toBe('off');
});
