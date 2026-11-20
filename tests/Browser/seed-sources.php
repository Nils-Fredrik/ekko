<?php

use App\Models\Action;
use App\Models\AggDaily;
use App\Models\AggSourceDaily;
use App\Models\Brand;
use App\Models\BrandDomain;
use App\Models\Citation;
use App\Models\Competitor;
use App\Models\CompetitorDomain;
use App\Models\Engine;
use App\Models\Market;
use App\Models\Mention;
use App\Models\Prompt;
use App\Models\Response;
use App\Models\Run;
use App\Models\RunBatch;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// Synthetic sources for the U08 browser test (tests/Browser/sources.spec.mjs). Never run against an ordinary database.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = DB::connection('pgsql')->getDatabaseName();
if (getenv('EKKO_BROWSER_TEST') !== '1' || ! $app->environment('local', 'testing') || ! is_string($database) || ! str_ends_with($database, '_testing') || $database !== env('DB_TEST_DATABASE')) {
    fwrite(STDERR, "Browser fixtures require local/testing and a dedicated _testing database.\n");
    exit(1);
}
$tenantId = (int) getenv('SMOKE_TENANT_ID');
// A reader of its own: login is rate limited per address, and the other specs log in as the smoke user.
$reader = User::query()->where('email', 'browser-sources@example.test')->first();
if ($reader === null) {
    $reader = User::factory()->create(['email' => 'browser-sources@example.test', 'password' => Hash::make((string) getenv('SMOKE_PASSWORD')), 'locale' => 'en']);
    $team = Team::query()->findOrFail($tenantId);
    $team->users()->attach($reader, ['role' => 'reader']);
    $reader->switchTeam($team);
}
$result = app(TenantContext::class)->run($tenantId, fn (): array => DB::transaction(function (): array {
    $existing = Brand::query()->where('name', 'Browser sources brand')->first();
    if ($existing !== null) {
        return ['brand' => $existing->id, 'action' => Action::query()->where('brand_id', $existing->id)->value('id')];
    }
    $timezone = Team::query()->findOrFail(current_tenant_id())->timezone ?: 'UTC';
    $today = CarbonImmutable::now($timezone)->toDateString();
    $brand = Brand::factory()->create(['name' => 'Browser sources brand']);
    $market = Market::factory()->for($brand)->create();
    $competitor = Competitor::factory()->for($brand)->create(['name' => 'Browser rival']);
    $engine = Engine::query()->where('key', 'browser_sources')->first() ?? Engine::factory()->create(['key' => 'browser_sources']);
    $prompt = Prompt::factory()->for($market)->create(['text' => 'Which coffee roaster do you recommend?']);
    BrandDomain::factory()->create(['brand_id' => $brand->id, 'domain' => 'own-coffee.example.test']);
    CompetitorDomain::factory()->for($competitor)->create(['domain' => 'rival-coffee.example.test']);

    foreach ([['https://coffee-news.example.test/best-roasters', true], ['https://coffee-news.example.test/rival-review', false]] as [$url, $brandNamed]) {
        $batch = RunBatch::factory()->create(['market_id' => $market->id]);
        $run = Run::factory()->create(['run_batch_id' => $batch->id, 'prompt_id' => $prompt->id, 'engine_key' => $engine->key, 'status' => 'extracted']);
        $response = Response::factory()->create(['run_id' => $run->id, 'engine_key' => $engine->key, 'collected_at' => now()->subHour()]);
        Citation::factory()->create(['response_id' => $response->id, 'domain' => 'coffee-news.example.test', 'url' => $url, 'url_normalized' => $url, 'ownership' => 'third_party', 'source_type' => 'news']);
        Mention::factory()->create($brandNamed
            ? ['response_id' => $response->id, 'entity_type' => 'brand', 'entity_id' => $brand->id, 'detection' => 'deterministic']
            : ['response_id' => $response->id, 'entity_type' => 'competitor', 'entity_id' => $competitor->id, 'detection' => 'deterministic']);
    }

    AggDaily::factory()->create([
        'market_id' => $market->id, 'engine_key' => $engine->key, 'prompt_id' => $prompt->id, 'date' => $today,
        'responses' => 20, 'brand_mentioned' => 10, 'competitor_only_responses' => 8,
    ]);
    foreach ([
        ['coffee-news.example.test', 12, 4, ['third_party' => ['news' => 12]]],
        ['own-coffee.example.test', 9, 0, ['own' => ['other' => 9]]],
        ['rival-coffee.example.test', 7, 0, ['competitor' => ['other' => 7]]],
        ['coffee-forum.example.test', 5, 2, ['third_party' => ['forum' => 5]]],
    ] as [$domain, $responses, $gap, $distribution]) {
        AggSourceDaily::factory()->create([
            'market_id' => $market->id, 'brand_id' => $brand->id, 'engine_key' => $engine->key, 'domain' => $domain,
            'date' => $today, 'citations' => $responses, 'responses_with_domain' => $responses, 'gap_responses' => $gap,
            'citation_distribution' => $distribution,
        ]);
    }

    $action = Action::factory()->create([
        'brand_id' => $brand->id, 'market_id' => $market->id, 'title' => 'Be covered on coffee-news.example.test',
        'evidence' => ['domain' => 'coffee-news.example.test'],
    ]);

    return ['brand' => $brand->id, 'action' => $action->id];
}));
echo json_encode([...$result, 'email' => $reader->email], JSON_THROW_ON_ERROR);
