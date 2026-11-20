<?php

use App\Models\AggDaily;
use App\Models\AiReferralDaily;
use App\Models\BotHitDaily;
use App\Models\Brand;
use App\Models\Engine;
use App\Models\Integration;
use App\Models\Market;
use App\Models\Prompt;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// Synthetic robot and traffic figures for the U09 browser test (tests/Browser/crawlers.spec.mjs). Never run against
// an ordinary database.
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
$reader = User::query()->where('email', 'browser-crawlers@example.test')->first();
if ($reader === null) {
    $reader = User::factory()->create(['email' => 'browser-crawlers@example.test', 'password' => Hash::make((string) getenv('SMOKE_PASSWORD')), 'locale' => 'en']);
    $team = Team::query()->findOrFail($tenantId);
    $team->users()->attach($reader, ['role' => 'reader']);
    $reader->switchTeam($team);
}
$result = app(TenantContext::class)->run($tenantId, fn (): array => DB::transaction(function (): array {
    $existing = Brand::query()->where('name', 'Browser crawlers brand')->first();
    if ($existing !== null) {
        $day = BotHitDaily::query()->where('brand_id', $existing->id)->where('bot', 'gptbot')->value('date');

        return ['brand' => $existing->id, 'day' => substr((string) $day, 0, 10)];
    }
    $timezone = Team::query()->findOrFail(current_tenant_id())->timezone ?: 'UTC';
    $today = CarbonImmutable::now($timezone);
    $yesterday = $today->subDay()->toDateString();
    $brand = Brand::factory()->create(['name' => 'Browser crawlers brand', 'primary_domain' => 'crawled-coffee.example.test']);
    $market = Market::factory()->for($brand)->create();
    $engine = Engine::query()->where('key', 'browser_crawlers')->first() ?? Engine::factory()->create(['key' => 'browser_crawlers']);
    $prompt = Prompt::factory()->for($market)->create(['text' => 'Which coffee roaster ships fastest?']);
    Integration::factory()->create(['brand_id' => $brand->id, 'type' => 'ga4']);

    // Fetched: 30 + 5 + 12 = 47 requests. Cited: 4 of 20 answers. Visits: 8 + 3 = 11 sessions.
    foreach ([
        [$yesterday, 'oai_searchbot', 30, [['path' => '/guide', 'hits' => 20], ['path' => '/', 'hits' => 10]]],
        [$yesterday, 'gptbot', 5, [['path' => '/guide', 'hits' => 5]]],
        [$today->toDateString(), 'oai_searchbot', 12, [['path' => '/', 'hits' => 12]]],
    ] as [$date, $bot, $hits, $paths]) {
        BotHitDaily::factory()->create([
            'brand_id' => $brand->id, 'date' => $date, 'bot' => $bot, 'hits' => $hits,
            'unique_paths' => count($paths), 'top_paths' => $paths,
        ]);
    }
    AggDaily::factory()->create([
        'market_id' => $market->id, 'engine_key' => $engine->key, 'prompt_id' => $prompt->id,
        'date' => $yesterday, 'responses' => 20, 'own_cited' => 4,
    ]);
    foreach ([[$yesterday, 'chatgpt.com', 8], [$today->toDateString(), 'perplexity.ai', 3]] as [$date, $source, $sessions]) {
        AiReferralDaily::factory()->create([
            'brand_id' => $brand->id, 'date' => $date, 'source' => $source,
            'sessions' => $sessions, 'engaged_sessions' => 2, 'conversions' => '1.0000',
        ]);
    }

    return ['brand' => $brand->id, 'day' => $yesterday];
}));
echo json_encode([...$result, 'email' => $reader->email], JSON_THROW_ON_ERROR);
